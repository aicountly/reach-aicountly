<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Libraries\Support\PgBoolean;
use RuntimeException;

/**
 * Creates the public-site records an answer depends on, before it is published.
 *
 * The receiver's publish route transitions an answer that already exists on
 * aicountly.com; it does not create one. Reach only ever called publishAnswer(),
 * so the receiver answered 404 "Answer not found on the public site." for every
 * publish ever attempted. The client methods for the missing steps existed —
 * createIdentity/createQuestion/createAnswer — with no caller anywhere.
 *
 * Order matters and is enforced by the receiver: an answer is rejected unless
 * its parent question exists, and a question authored by an official identity
 * is rejected unless that identity exists and is active. So: identity →
 * question → answer → publish.
 *
 * Every step is an upsert on the receiver side (keyed by Reach's UUID), so this
 * runs unconditionally before each publish rather than tracking "already
 * provisioned" state that could drift. That also means edits made in Reach
 * after a first publish reach the public site on the next publish.
 *
 * Idempotency keys are derived from the request body (see idempotencyKey()).
 * The receiver keeps every key it has seen and answers 409 "Idempotency key
 * was already used with a different request body." when one comes back with
 * other content; it never expires them. The keys used to be one per record
 * ("reach-question-<uuid>"), so the first time a record's envelope changed —
 * the desk disclosure was added to the question payload on 17 Oct, a category
 * was remapped, a desk was renamed — every later publish touching that record
 * failed for good. That is what dead-lettered all seven re-vet corrections on
 * 23 Oct ("Public question provisioning failed … (version_conflict)").
 */
class CommunityPublicRecordProvisioner
{
    /**
     * Reach models operational roles by job title; the public site models them
     * by the function the account performs. Unmapped roles fall back to
     * 'answering' — the receiver rejects anything outside its own enum, and
     * refusing to publish over a vocabulary gap would be worse than posting
     * under the most common role.
     */
    private const ROLE_MAP = [
        'expert_answer_assistant' => 'answering',
        'thread_facilitator'      => 'facilitation',
        'community_steward'       => 'moderation',
        'question_curator'        => 'curation',
        'review_objection_desk'   => 'editorial',
    ];

    private const DEFAULT_ROLE = 'answering';

    /**
     * What every desk post tells readers about how it was made. Matches the
     * public site's own desk label (aicountly-com community_desk_answer_label):
     * desk content is AI-assisted and checked automatically — no person
     * reviews it, and nothing sent from here may claim one did.
     */
    public const DESK_DISCLOSURE = 'Prepared with AI assistance and automatically checked for accuracy by the AICOUNTLY desk.';

    /**
     * Reach category → public category slug.
     *
     * The two systems grew separate taxonomies: Reach files questions under
     * 'audit', 'accounting', 'payroll-hr', 'product-guides'; aicountly.com
     * publishes 'audit-accounting', 'payroll', 'saas-product-help'. The
     * receiver rejects anything it does not recognise with "Unknown community
     * category.", and the fallback for that drops the category entirely — which
     * files a tax-audit question under GST, the first category by sort order.
     * Mapping the known divergences keeps content where a reader would look for
     * it; anything unmapped is passed through unchanged, so a slug the public
     * site already knows still works without an entry here.
     *
     * This is a bridge, not a design: the durable fix is one shared taxonomy.
     */
    private const CATEGORY_MAP = [
        'audit'           => 'audit-accounting',
        'accounting'      => 'audit-accounting',
        'audit-accounting' => 'audit-accounting',
        'payroll-hr'      => 'payroll',
        'hr'              => 'payroll',
        'product-guides'  => 'saas-product-help',
        'books'           => 'saas-product-help',
        'smart-books'     => 'saas-product-help',
        'product'         => 'saas-product-help',
        'banking'         => 'banking-brs',
        'brs'             => 'banking-brs',
        'technical'       => 'technical-api',
        'api'             => 'technical-api',
        'integration'     => 'technical-api',
        'mca'             => 'mca-company-law',
        'company-law'     => 'mca-company-law',
        'tds'             => 'tds-tcs',
        'tcs'             => 'tds-tcs',
    ];

    public function __construct(
        private readonly CommunityPublisherInterface $publisher = new CommunityPublicSitePublisher(),
    ) {}

    /**
     * Translate a Reach operational role into the receiver's vocabulary.
     * Public and static so the mapping is testable without a database.
     */
    public static function publicRoleFor(?string $reachRole): string
    {
        return self::ROLE_MAP[strtolower(trim((string) $reachRole))] ?? self::DEFAULT_ROLE;
    }

    /**
     * Translate a Reach category into the public site's category slug.
     * Unmapped values pass through — the receiver knows its own slugs, and an
     * unrecognised one still degrades to the default-category fallback.
     */
    public static function publicCategoryFor(?string $reachCategory): string
    {
        $slug = strtolower(trim((string) $reachCategory));

        return self::CATEGORY_MAP[$slug] ?? $slug;
    }

    /**
     * Ensure identity, question and answer all exist publicly for this answer.
     *
     * @param array      $answer  Row from reach_community_official_answers.
     * @param array|null $version The approved version being published.
     *
     * @throws RuntimeException when any step is rejected by the receiver.
     */
    public function ensureAnswerExists(array $answer, ?array $version): void
    {
        $db = db_connect();

        $identity = $db->table('reach_community_official_identities')
            ->where('id', (int) $answer['identity_id'])->get()->getRowArray();
        if ($identity === null) {
            throw new RuntimeException("Answer #{$answer['id']} has no official identity to publish under.");
        }

        $question = $db->table('reach_community_questions')
            ->where('id', (int) $answer['question_id'])->get()->getRowArray();
        if ($question === null) {
            throw new RuntimeException("Answer #{$answer['id']} has no question to attach to.");
        }

        $identitySlug = $this->ensureIdentity($identity);

        // A member's own question from aicountly.com already has its thread
        // there: the answer goes onto that post. Upserting the question would
        // re-post the member's words as a desk FAQ page (the receiver refuses
        // to rewrite a member's post anyway).
        $memberPostId = self::memberPostIdOf($question);
        if ($memberPostId === null) {
            $this->ensureQuestion($question, $identitySlug);
        }
        $this->ensureAnswer($answer, $question, $identitySlug, $version, $memberPostId);
    }

    /** The aicountly.com post id of a member's question pulled from the site, or null. */
    public static function memberPostIdOf(array $question): ?int
    {
        if (($question['source_type'] ?? '') !== 'public_submission') {
            return null;
        }
        $id = (string) ($question['public_external_id'] ?? '');
        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    /** @return string The identity slug the public site knows this identity by. */
    private function ensureIdentity(array $identity): string
    {
        $slug = trim((string) ($identity['public_slug'] ?? '')) ?: (string) $identity['slug'];

        $payload = [
            'slug'                 => $slug,
            'display_name'         => (string) $identity['display_name'],
            'operational_role'     => self::publicRoleFor($identity['operational_role'] ?? null),
            'ai_disclosure'        => (string) ($identity['ai_disclosure'] ?? ''),
            'short_description'    => (string) ($identity['short_description'] ?? $identity['disclosure_template'] ?? ''),
            'topic_specialisation' => (string) ($identity['topic_specialisation'] ?? $identity['department'] ?? ''),
            'is_active'            => (bool) $identity['is_active'],
        ];

        // avatar_reference is a free-form pointer in Reach; the receiver runs it
        // through a URL allow-list and 422s anything else, so only forward it
        // when it is actually a URL.
        $avatar = trim((string) ($identity['avatar_reference'] ?? ''));
        if ($avatar !== '' && preg_match('#^https?://#i', $avatar) === 1) {
            $payload['avatar_url'] = $avatar;
        }

        $result = $this->publisher->createIdentity(self::keyed([
            'reach_identity_uuid' => (string) $identity['uuid'],
            'operation'           => 'create_identity',
            'payload'             => array_filter($payload, static fn ($v) => $v !== ''),
        ], 'identity', (string) $identity['uuid']));

        $this->assertOk($result, 'identity', (string) $identity['uuid']);

        if (! empty($result['public_identity_id'])) {
            db_connect()->table('reach_community_official_identities')
                ->where('id', (int) $identity['id'])
                ->update([
                    'public_external_id' => (string) $result['public_identity_id'],
                    'updated_at'         => date('Y-m-d H:i:s'),
                ]);
        }

        return (string) ($result['identity_slug'] ?? $slug);
    }

    private function ensureQuestion(array $question, string $identitySlug): void
    {
        // The receiver rejects a body that sanitises to empty. Reach allows an
        // empty question body (the title often carries the whole question), so
        // fall back to the title rather than failing the publish.
        $body = trim((string) ($question['body'] ?? ''));
        if ($body === '') {
            $body = (string) $question['title'];
        }

        $envelope = [
            'reach_question_uuid'          => (string) $question['uuid'],
            'operation'                    => 'create_question',
            'content_type'                 => 'community_question',
            'official_identity_slug'       => $identitySlug,
            'reach_content_version_number' => 1,
            'payload'                      => [
                'title'            => (string) $question['title'],
                'body'             => $body,
                'author_type'      => 'official_bot',
                'ai_assisted'      => true,
                'ai_disclosure'    => self::DESK_DISCLOSURE,
                'status'           => 'published',
                'robots_directive' => 'index,follow',
                'category_slug'    => self::publicCategoryFor($question['category'] ?? null),
            ],
        ];

        $result = $this->publisher->createQuestion(self::keyed($envelope, 'question', (string) $question['uuid']));

        // An unknown category is the one rejection worth recovering from: the
        // receiver falls back to its default category when none is supplied, so
        // a taxonomy mismatch between the two systems costs a misfiled question
        // rather than a failed publication. Anything else is a real error.
        if (! ($result['success'] ?? false) && $this->isUnknownCategory($result)) {
            unset($envelope['payload']['category_slug']);

            AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_INTAKE, [
                'question_id'      => (int) $question['id'],
                'note'             => 'public site rejected category; published under its default category',
                'category_offered' => (string) ($question['category'] ?? ''),
            ]);

            $result = $this->publisher->createQuestion(self::keyed($envelope, 'question', (string) $question['uuid']));
        }

        $this->assertOk($result, 'question', (string) $question['uuid']);

        $update = ['updated_at' => date('Y-m-d H:i:s')];
        if (! empty($result['public_question_id'])) {
            $update['public_external_id'] = (string) $result['public_question_id'];
        }
        if (! empty($result['canonical_url'])) {
            $update['public_url'] = (string) $result['canonical_url'];
        }

        if (count($update) > 1) {
            db_connect()->table('reach_community_questions')
                ->where('id', (int) $question['id'])
                ->update($update);
        }
    }

    private function ensureAnswer(array $answer, array $question, string $identitySlug, ?array $version, ?int $memberPostId = null): void
    {
        $body = trim((string) ($version['content'] ?? ''));
        if ($body === '') {
            throw new RuntimeException(
                "Answer #{$answer['id']} has no content in its approved version; nothing to publish."
            );
        }

        $result = $this->publisher->createAnswer(self::keyed(array_filter([
            'reach_answer_uuid'            => (string) $answer['uuid'],
            'reach_question_uuid'          => (string) $question['uuid'],
            // Answer the member's post itself (the receiver links it to the uuid above).
            'public_question_id'           => $memberPostId,
            'operation'                    => 'create_answer',
            'content_type'                 => 'community_answer',
            'official_identity_slug'       => $identitySlug,
            'reach_content_version_number' => (int) ($answer['approved_version'] ?? 1),
            'payload' => [
                'body'             => $body,
                'excerpt'          => (string) ($version['excerpt'] ?? ''),
                'author_type'      => 'official_bot',
                // The receiver requires official-identity content to declare
                // ai_assisted = true. Reach's own flag is forwarded rather than
                // forced: a human-written answer posted under a bot identity is
                // a disclosure question for a person to answer, not something
                // to paper over here.
                // Postgres hands booleans back as 't'/'f', and (bool) 'f' is
                // true — so this used to claim AI assistance for every answer.
                'ai_assisted'      => PgBoolean::isTrue($answer['ai_assisted'] ?? false),
                'ai_disclosure'    => self::DESK_DISCLOSURE,
                'robots_directive' => 'index,follow',
            ],
        ], static fn ($v) => $v !== null), 'answer', (string) $answer['uuid']));

        $this->assertOk($result, 'answer', (string) $answer['uuid']);

        if (! empty($result['public_answer_id'])) {
            db_connect()->table('reach_community_official_answers')
                ->where('id', (int) $answer['id'])
                ->update([
                    'public_external_id' => (string) $result['public_answer_id'],
                    'updated_at'         => date('Y-m-d H:i:s'),
                ]);
        }
    }

    private function isUnknownCategory(array $result): bool
    {
        $message = strtolower((string) ($result['safe_error_message'] ?? ''));

        return str_contains($message, 'unknown community category');
    }

    /** @throws RuntimeException */
    private function assertOk(array $result, string $record, string $uuid): void
    {
        if ($result['success'] ?? false) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Public %s provisioning failed for %s: %s (%s)',
            $record,
            $uuid,
            $result['safe_error_message'] ?? 'unknown error',
            $result['error_category'] ?? 'unknown',
        ));
    }

    /** The envelope with its idempotency key (computed over everything else in it). */
    private static function keyed(array $envelope, string $kind, string $uuid): array
    {
        unset($envelope['idempotency_key']);
        $envelope['idempotency_key'] = self::idempotencyKey($kind, $uuid, $envelope);

        return $envelope;
    }

    /**
     * One key per record AND content: "reach-{i|q|a}-<uuid>-<16 hex of the body>".
     *
     * A retry re-sends the identical upsert, so it carries the same key and
     * the receiver replays its first answer; a record whose envelope has
     * changed since it was last sent gets a new key, so the receiver applies
     * the upsert instead of refusing it as a reused key with a different body
     * (409, kept forever). Public and static so it is testable without a
     * database. At most 64 characters (a Reach UUID is 36).
     */
    public static function idempotencyKey(string $kind, string $uuid, array $envelope): string
    {
        unset($envelope['idempotency_key']);
        $hash   = substr(hash('sha256', (string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
        $prefix = 'reach-' . (['identity' => 'i', 'question' => 'q', 'answer' => 'a'][$kind] ?? substr($kind, 0, 1)) . '-' . $uuid;

        return substr($prefix, 0, 64 - 17) . '-' . $hash;
    }
}
