<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
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
        $this->ensureQuestion($question, $identitySlug);
        $this->ensureAnswer($answer, $question, $identitySlug, $version);
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

        $result = $this->publisher->createIdentity([
            'reach_identity_uuid' => (string) $identity['uuid'],
            'operation'           => 'create_identity',
            'idempotency_key'     => $this->idempotencyKey('identity', (string) $identity['uuid']),
            'payload'             => array_filter($payload, static fn ($v) => $v !== ''),
        ]);

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
            'idempotency_key'              => $this->idempotencyKey('question', (string) $question['uuid']),
            'payload'                      => [
                'title'            => (string) $question['title'],
                'body'             => $body,
                'author_type'      => 'official_bot',
                'ai_assisted'      => true,
                'status'           => 'published',
                'robots_directive' => 'index,follow',
                'category_slug'    => (string) ($question['category'] ?? ''),
            ],
        ];

        $result = $this->publisher->createQuestion($envelope);

        // An unknown category is the one rejection worth recovering from: the
        // receiver falls back to its default category when none is supplied, so
        // a taxonomy mismatch between the two systems costs a misfiled question
        // rather than a failed publication. Anything else is a real error.
        if (! ($result['success'] ?? false) && $this->isUnknownCategory($result)) {
            unset($envelope['payload']['category_slug']);
            $envelope['idempotency_key'] = $this->idempotencyKey('question-nocat', (string) $question['uuid']);

            AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_INTAKE, [
                'question_id'      => (int) $question['id'],
                'note'             => 'public site rejected category; published under its default category',
                'category_offered' => (string) ($question['category'] ?? ''),
            ]);

            $result = $this->publisher->createQuestion($envelope);
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

    private function ensureAnswer(array $answer, array $question, string $identitySlug, ?array $version): void
    {
        $body = trim((string) ($version['content'] ?? ''));
        if ($body === '') {
            throw new RuntimeException(
                "Answer #{$answer['id']} has no content in its approved version; nothing to publish."
            );
        }

        $result = $this->publisher->createAnswer([
            'reach_answer_uuid'            => (string) $answer['uuid'],
            'reach_question_uuid'          => (string) $question['uuid'],
            'operation'                    => 'create_answer',
            'content_type'                 => 'community_answer',
            'official_identity_slug'       => $identitySlug,
            'reach_content_version_number' => (int) ($answer['approved_version'] ?? 1),
            'idempotency_key'              => $this->idempotencyKey(
                'answer-v' . (int) ($answer['approved_version'] ?? 1),
                (string) $answer['uuid'],
            ),
            'payload' => [
                'body'             => $body,
                'excerpt'          => (string) ($version['excerpt'] ?? ''),
                'author_type'      => 'official_bot',
                // The receiver requires official-identity content to declare
                // ai_assisted = true. Reach's own flag is forwarded rather than
                // forced: a human-written answer posted under a bot identity is
                // a disclosure question for a person to answer, not something
                // to paper over here.
                'ai_assisted'      => (bool) ($answer['ai_assisted'] ?? false),
                'robots_directive' => 'index,follow',
            ],
        ]);

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

    /**
     * Stable per-record key so a retried publish re-sends the same upsert
     * instead of minting a new one the receiver would treat as fresh work.
     */
    private function idempotencyKey(string $kind, string $uuid): string
    {
        return substr('reach-' . $kind . '-' . $uuid, 0, 64);
    }
}
