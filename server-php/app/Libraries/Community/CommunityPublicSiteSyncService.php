<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Makes sure the public site already holds the records an answer publish
 * depends on.
 *
 * `POST /community/v1/answers/{uuid}/publish` on aicountly.com is a *state
 * transition*: it 404s with "Answer not found on the public site" unless the
 * answer row exists, and creating an answer 404s unless its parent question
 * exists, and both 404 unless the authoring official identity exists. Reach
 * only ever called the publish endpoint, so no community Q&A could ever reach
 * aicountly.com regardless of how many answers were approved here.
 *
 * This service performs the missing upserts, in dependency order, immediately
 * before the publish call:
 *
 *     identity  ->  question  ->  answer  ->  (caller publishes)
 *
 * Every receiver endpoint used here is an upsert keyed on the Reach external
 * id, so re-running it for an already-synced answer is a no-op that costs one
 * HTTP round trip. Failures are returned, never thrown: the caller decides
 * whether a missing prerequisite should fail the deployment.
 */
class CommunityPublicSiteSyncService
{
    /**
     * Reach operational roles -> the roles the public receiver accepts
     * (`CommunityReceiverController::OPERATIONAL_ROLES`). A value outside that
     * enum is rejected with a 422, so the mapping is mandatory, not cosmetic.
     *
     * @var array<string,string>
     */
    private const ROLE_MAP = [
        'expert_answer_assistant' => 'answering',
        'thread_facilitator'      => 'facilitation',
        'question_curator'        => 'curation',
        'community_steward'       => 'moderation',
        'review_objection_desk'   => 'editorial',
    ];

    /**
     * Reach question categories -> public community category slugs (seeded by
     * CommunityService::seedCategories on aicountly.com). An unknown slug is a
     * 422 from the receiver, so anything unmapped falls back to the answering
     * desk's own subject area instead.
     *
     * @var array<string,string>
     */
    private const CATEGORY_MAP = [
        'gst'                 => 'gst',
        'income-tax'          => 'income-tax',
        'income_tax'          => 'income-tax',
        'tds-tcs'             => 'tds-tcs',
        'tds'                 => 'tds-tcs',
        'tcs'                 => 'tds-tcs',
        'company-law'         => 'mca-company-law',
        'mca'                 => 'mca-company-law',
        'mca-company-law'     => 'mca-company-law',
        'secretarial'         => 'mca-company-law',
        'accounting'          => 'audit-accounting',
        'audit'               => 'audit-accounting',
        'audit-accounting'    => 'audit-accounting',
        'financial-reporting' => 'audit-accounting',
        'bookkeeping'         => 'audit-accounting',
        'payroll'             => 'payroll',
        'payroll-hr'          => 'payroll',
        'hr'                  => 'payroll',
        'banking'             => 'banking-brs',
        'banking-brs'         => 'banking-brs',
        'brs'                 => 'banking-brs',
        'product-guides'      => 'saas-product-help',
        'product'             => 'saas-product-help',
        'books'               => 'saas-product-help',
        'smart-books'         => 'saas-product-help',
        'saas-product-help'   => 'saas-product-help',
        'api'                 => 'technical-api',
        'technical'           => 'technical-api',
        'technical-api'       => 'technical-api',
        'integration'         => 'technical-api',
    ];

    /** Answering desk -> subject area, used when the question has no category. */
    private const DESK_CATEGORY = [
        'aicountly-gst-guide'         => 'gst',
        'aicountly-income-tax-desk'   => 'income-tax',
        'aicountly-payroll-desk'      => 'payroll',
        'aicountly-smart-books-guide' => 'saas-product-help',
        'aicountly-accounting-guide'  => 'audit-accounting',
        'aicountly-compliance-desk'   => 'mca-company-law',
    ];

    private const DEFAULT_CATEGORY = 'audit-accounting';

    private BaseConnection $db;

    public function __construct(
        private ?CommunityPublisherInterface $publisher = null,
    ) {
        $this->db        = Database::connect();
        $this->publisher ??= CommunityPublisherFactory::create();
    }

    /**
     * Push identity + question + answer so the answer exists on the public site
     * and can be transitioned to published.
     *
     * @param array<string,mixed>      $answer  reach_community_official_answers row
     * @param array<string,mixed>|null $version the approved answer version
     *
     * @return array{synced:bool,steps:array<string,mixed>,error_category?:string,safe_error_message?:string}
     */
    public function ensureAnswerExists(array $answer, ?array $version): array
    {
        $steps = [];

        $identitySlug = $this->identitySlugFor($answer);
        if ($identitySlug === '') {
            return $this->failure($steps, 'validation_error', 'Answer has no official identity to publish under.');
        }

        $identity = $this->db->table('reach_community_official_identities')
            ->where('slug', $identitySlug)->get()->getRowArray();
        if (! $identity) {
            return $this->failure($steps, 'validation_error', "Official identity '{$identitySlug}' not found in Reach.");
        }

        $question = $this->db->table('reach_community_questions')
            ->where('id', (int) $answer['question_id'])->get()->getRowArray();
        if (! $question) {
            return $this->failure($steps, 'validation_error', 'Answer has no parent question in Reach.');
        }

        $identityResult = $this->publisher->createIdentity($this->identityEnvelope($identity));
        $steps['identity'] = $this->step($identityResult, $identitySlug);
        if (! ($identityResult['success'] ?? false)) {
            return $this->failure($steps, $identityResult['error_category'] ?? 'unknown', 'Official identity sync failed: '
                . ($identityResult['safe_error_message'] ?? 'unknown'));
        }

        // Questions are curated by the curator desk when one exists, so the
        // public byline separates "who asked" from "who answered".
        $questionIdentity = $this->questionIdentity() ?? $identity;
        if ((string) ($questionIdentity['slug'] ?? '') !== $identitySlug) {
            $curatorResult = $this->publisher->createIdentity($this->identityEnvelope($questionIdentity));
            $steps['question_identity'] = $this->step($curatorResult, (string) $questionIdentity['slug']);
            if (! ($curatorResult['success'] ?? false)) {
                // Fall back to the answering identity rather than failing the
                // publish over a byline.
                $questionIdentity = $identity;
            }
        }

        $questionResult = $this->publisher->createQuestion(
            $this->questionEnvelope($question, $questionIdentity, $identitySlug)
        );
        $steps['question'] = $this->step($questionResult, (string) $question['uuid']);
        if (! ($questionResult['success'] ?? false)) {
            return $this->failure($steps, $questionResult['error_category'] ?? 'unknown', 'Question sync failed: '
                . ($questionResult['safe_error_message'] ?? 'unknown'));
        }

        $answerResult = $this->publisher->createAnswer(
            $this->answerEnvelope($answer, $version, $question, $identitySlug, $identity)
        );
        $steps['answer'] = $this->step($answerResult, (string) $answer['uuid']);
        if (! ($answerResult['success'] ?? false)) {
            return $this->failure($steps, $answerResult['error_category'] ?? 'unknown', 'Answer sync failed: '
                . ($answerResult['safe_error_message'] ?? 'unknown'));
        }

        AuditLogger::record('community.public_site_prerequisites_synced', [
            'answer_id'          => (int) $answer['id'],
            'question_id'        => (int) $answer['question_id'],
            'public_question_id' => $questionResult['public_question_id'] ?? null,
            'public_answer_id'   => $answerResult['public_answer_id'] ?? null,
        ]);

        return ['synced' => true, 'steps' => $steps];
    }

    // -------------------------------------------------------------------------
    // Envelopes
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function identityEnvelope(array $identity): array
    {
        $slug        = (string) ($identity['public_slug'] ?: $identity['slug']);
        $disclosure  = trim((string) ($identity['ai_disclosure'] ?? $identity['disclosure_template'] ?? ''));
        $reachRole   = strtolower(trim((string) ($identity['operational_role'] ?? '')));

        return [
            'reach_identity_uuid' => (string) $identity['uuid'],
            'idempotency_key'     => 'identity-' . $identity['uuid'] . '-' . substr(sha1(
                $slug . '|' . $identity['display_name'] . '|' . $reachRole . '|' . $disclosure
            ), 0, 16),
            'payload' => [
                'slug'                 => $slug,
                'display_name'         => (string) $identity['display_name'],
                'short_description'    => (string) ($identity['short_description'] ?? ''),
                'topic_specialisation' => mb_substr((string) ($identity['topic_specialisation'] ?? ''), 0, 200),
                'operational_role'     => self::ROLE_MAP[$reachRole] ?? 'answering',
                'ai_disclosure'        => $disclosure !== ''
                    ? mb_substr($disclosure, 0, 255)
                    : 'AI-assisted AICOUNTLY community contributor',
                'is_active'            => (bool) ($identity['is_active'] ?? true),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $question
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function questionEnvelope(array $question, array $identity, string $answeringSlug): array
    {
        $title = trim((string) ($question['title'] ?? ''));
        $body  = trim((string) ($question['body'] ?? ''));
        if ($body === '') {
            // The receiver rejects an empty body; a curated one-line question
            // legitimately has none, so the title carries it.
            $body = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return [
            'reach_question_uuid'          => (string) $question['uuid'],
            'content_type'                 => 'community_question',
            'official_identity_slug'       => (string) ($identity['public_slug'] ?: $identity['slug']),
            'reach_content_version_number' => 1,
            'idempotency_key'              => 'question-' . $question['uuid'] . '-' . substr(sha1($title . '|' . $body), 0, 16),
            'payload' => [
                'title'            => mb_substr($title, 0, 255),
                'body'             => $body,
                'category_slug'    => $this->categorySlugFor($question, $answeringSlug),
                'status'           => 'published',
                'author_type'      => 'official_bot',
                'ai_assisted'      => true,
                'robots_directive' => 'index,follow',
                'tags'             => $this->tagsFor($question),
            ],
        ];
    }

    /**
     * @param array<string,mixed>      $answer
     * @param array<string,mixed>|null $version
     * @param array<string,mixed>      $question
     * @param array<string,mixed>      $identity
     * @return array<string,mixed>
     */
    public function answerEnvelope(
        array $answer,
        ?array $version,
        array $question,
        string $identitySlug,
        array $identity,
    ): array {
        $body    = (string) ($version['content'] ?? '');
        $excerpt = mb_substr((string) ($version['excerpt'] ?? ''), 0, 1000);
        $number  = (int) ($answer['approved_version'] ?? $version['version_number'] ?? 1);

        return [
            'reach_answer_uuid'            => (string) $answer['uuid'],
            'reach_question_uuid'          => (string) $question['uuid'],
            'content_type'                 => 'community_answer',
            'official_identity_slug'       => $identitySlug,
            'reach_content_version_number' => $number,
            'idempotency_key'              => 'answer-' . $answer['uuid'] . '-v' . $number . '-' . substr(sha1($body), 0, 16),
            'payload' => [
                'body'             => $body,
                'excerpt'          => $excerpt,
                'answer_version'   => $number,
                'author_type'      => 'official_bot',
                // Mandatory for official-bot content: the receiver rejects a
                // bot answer that does not declare AI assistance + disclosure.
                'ai_assisted'      => true,
                'ai_disclosure'    => mb_substr(trim((string) ($identity['ai_disclosure'] ?? '')), 0, 255)
                                      ?: 'AI-assisted AICOUNTLY community contributor',
                'robots_directive' => 'index,follow',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $answer */
    private function identitySlugFor(array $answer): string
    {
        $slug = trim((string) ($answer['identity_slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        $identityId = (int) ($answer['identity_id'] ?? 0);
        if ($identityId <= 0) {
            return '';
        }

        return (string) ($this->db->table('reach_community_official_identities')
            ->select('slug')->where('id', $identityId)->get()->getRowArray()['slug'] ?? '');
    }

    /** @return array<string,mixed>|null */
    private function questionIdentity(): ?array
    {
        return $this->db->table('reach_community_official_identities')
            ->where('slug', 'aicountly-question-curator')
            ->where('is_active', true)
            ->get()->getRowArray();
    }

    /** @param array<string,mixed> $question */
    private function categorySlugFor(array $question, string $answeringSlug): string
    {
        $raw = strtolower(trim((string) ($question['category'] ?? '')));
        $raw = str_replace([' ', '_', '/'], '-', $raw);

        return self::CATEGORY_MAP[$raw]
            ?? self::DESK_CATEGORY[$answeringSlug]
            ?? self::DEFAULT_CATEGORY;
    }

    /**
     * @param array<string,mixed> $question
     * @return list<string>
     */
    private function tagsFor(array $question): array
    {
        $tags = $question['tags'] ?? [];
        if (is_string($tags)) {
            // Postgres text[] arrives as "{a,b}" through some drivers.
            $tags = trim($tags, '{}') === '' ? [] : explode(',', trim($tags, '{}'));
        }
        if (! is_array($tags)) {
            return [];
        }

        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag, " \t\n\r\0\x0B\"");
            if ($tag !== '') {
                $clean[] = mb_substr($tag, 0, 80);
            }
        }

        return array_values(array_slice(array_unique($clean), 0, 10));
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function step(array $result, string $reference): array
    {
        return array_filter([
            'reference' => $reference,
            'success'   => (bool) ($result['success'] ?? false),
            'operation' => $result['operation'] ?? null,
            'error'     => $result['success'] ?? false ? null : ($result['safe_error_message'] ?? 'unknown'),
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string,mixed> $steps
     * @return array{synced:bool,steps:array<string,mixed>,error_category:string,safe_error_message:string}
     */
    private function failure(array $steps, string $category, string $message): array
    {
        try {
            AuditLogger::record('community.public_site_prerequisites_failed', [
                'error_category' => $category,
                'message'        => mb_substr($message, 0, 200),
                'steps'          => $steps,
            ]);
        } catch (Throwable) {
            // Auditing must never mask the original failure.
        }

        return [
            'synced'             => false,
            'steps'              => $steps,
            'error_category'     => $category,
            'safe_error_message' => $message,
        ];
    }
}
