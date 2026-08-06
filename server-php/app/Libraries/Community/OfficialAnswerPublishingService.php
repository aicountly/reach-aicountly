<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityRiskTier;
use App\Libraries\AuditLogger;
use App\Models\CommunityAnswerApprovalModel;
use App\Models\CommunityDeploymentModel;

/**
 * Orchestrates the secure publishing of approved official answers to aicountly-com.
 *
 * The public site is a separate system that holds its own records, so — exactly
 * like the Phase 4 blog path, which calls create_draft before publish — a
 * community publication is a three-step conversation: create the question,
 * create the answer against it, then publish. Sending `publish` on its own left
 * aicountly.com with nothing to attach the answer to and nothing to render.
 * Both create steps are guarded by the stored public identifiers, so repeat
 * publications update rather than duplicate.
 *
 * Enforces:
 *   - Pre-publication approval checksum verification
 *   - Publish-time risk re-check (tier 4 always blocked; tier 2/3 must have a
 *     professional/compliance approval record) — approval-time gating in
 *     OfficialAnswerLifecycleService::approve() is necessary but not
 *     sufficient, because the risk tier can be raised after approval via
 *     setRiskTierWithOverride() without automatically revoking the existing
 *     approval. This re-check is the defense-in-depth backstop.
 *   - Idempotent deployment records
 *   - HMAC-signed API calls via CommunityPublicSitePublisher
 *   - Status transition after successful/failed deployment
 */
class OfficialAnswerPublishingService
{
    public function __construct(
        private readonly OfficialAnswerRepository      $answerRepo     = new OfficialAnswerRepository(),
        private readonly OfficialAnswerApprovalService  $approval       = new OfficialAnswerApprovalService(),
        private readonly CommunityDeploymentModel       $deployModel    = new CommunityDeploymentModel(),
        private readonly CommunityAnswerApprovalModel    $approvalModel  = new CommunityAnswerApprovalModel(),
        private readonly CommunityQuestionRepository     $questionRepo   = new CommunityQuestionRepository(),
        private CommunityPublisherInterface              $publisher      = new CommunityPublicSitePublisher()
    ) {
        // Allow factory injection for test environments
        $this->publisher = CommunityPublisherFactory::create();
    }

    /**
     * Re-derive the risk tier the same way OfficialAnswerLifecycleService
     * does. Duplicated rather than shared because the lifecycle service
     * depends on this service (not vice versa) — introducing a back-reference
     * would create a circular dependency for a three-line lookup.
     */
    private function riskTierOf(array $answer): CommunityRiskTier
    {
        if (isset($answer['risk_tier']) && $answer['risk_tier'] !== null && $answer['risk_tier'] !== '') {
            return CommunityRiskTier::from((int) $answer['risk_tier']);
        }
        return CommunityRiskTier::fromClassification($answer['risk_classification'] ?? 'low');
    }

    /**
     * Publish-time risk gate. Throws when the current risk tier can never
     * publish (tier 4) or requires an approval record this answer does not
     * actually have (tier 2/3 must be professional_review/compliance_review,
     * never standard) — re-asserted here because the tier may have changed
     * since the stored approval was granted.
     */
    private function assertPublishableRiskState(array $answer): void
    {
        $answerId = (int) $answer['id'];
        $tier     = $this->riskTierOf($answer);

        if ($tier === CommunityRiskTier::Prohibited) {
            AuditLogger::record(AuditLogger::COMMUNITY_PUBLISHING_RISK_BLOCKED, [
                'answer_id' => $answerId,
                'risk_tier' => $tier->value,
                'reason'    => 'risk_tier_prohibited',
            ]);
            throw new \RuntimeException(
                "Publication blocked: answer #{$answerId} is risk tier 4 (prohibited) and can never be published."
            );
        }

        if (! $tier->requiresProfessionalApproval()) {
            return;
        }

        $approvalRecord = $this->approvalModel
            ->where('answer_id', $answerId)
            ->where('answer_version_number', $answer['approved_version'] ?? null)
            ->where('version_checksum', $answer['approved_version_checksum'] ?? null)
            ->where('outcome', 'approved')
            ->orderBy('created_at', 'DESC')
            ->first();

        $approvalType = $approvalRecord['approval_type'] ?? 'standard';
        if (! in_array($approvalType, ['professional_review', 'compliance_review'], true)) {
            AuditLogger::record(AuditLogger::COMMUNITY_PUBLISHING_RISK_BLOCKED, [
                'answer_id'      => $answerId,
                'risk_tier'      => $tier->value,
                'approval_type'  => $approvalType,
                'reason'         => 'insufficient_approval_for_risk_tier',
            ]);
            throw new \RuntimeException(
                "Publication blocked: risk tier {$tier->value} requires professional or compliance approval; " .
                "recorded approval type for answer #{$answerId} was '{$approvalType}'."
            );
        }
    }

    /**
     * Publish an approved answer to the public site.
     */
    public function publish(int $answerId, ?int $actorId = null): array
    {
        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found");
        }

        // Gate: must be approved
        if ($answer['status'] !== CommunityAnswerStatus::Approved->value &&
            $answer['status'] !== CommunityAnswerStatus::Scheduled->value) {
            throw new \RuntimeException(
                "Answer must be approved before publishing. Current status: {$answer['status']}"
            );
        }

        // Gate: approval checksum must match
        if (!$this->approval->verifyApprovalForPublication($answer)) {
            throw new \RuntimeException(
                "Publication blocked: approval checksum verification failed for answer #{$answerId}"
            );
        }

        // Gate: risk tier may have been raised since approval — re-assert now.
        $this->assertPublishableRiskState($answer);

        // Never publish the same approved version twice. If a prior deployment
        // for this exact version + checksum already succeeded, return it.
        $priorSuccess = $this->deployModel
            ->where('answer_id', $answerId)
            ->where('answer_version_number', $answer['approved_version'])
            ->where('version_checksum', $answer['approved_version_checksum'])
            ->where('operation', 'publish')
            ->where('status', 'succeeded')
            ->first();

        if ($priorSuccess !== null) {
            return [
                'success'          => true,
                'duplicate'        => true,
                'public_answer_id' => $priorSuccess['public_answer_id'] ?? null,
                'canonical_url'    => $priorSuccess['public_url'] ?? null,
            ];
        }

        $version     = $this->answerRepo->getApprovedVersion($answer);
        $idempotency = bin2hex(random_bytes(16));

        // Create deployment record
        $deploymentId = $this->deployModel->insert([
            'answer_id'             => $answerId,
            'answer_version_number' => $answer['approved_version'],
            'version_checksum'      => $answer['approved_version_checksum'],
            'operation'             => 'publish',
            'idempotency_key'       => $idempotency,
            'status'                => 'executing',
            'attempt_count'         => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        $this->answerRepo->transitionStatus(
            $answerId,
            CommunityAnswerStatus::from($answer['status']),
            CommunityAnswerStatus::Publishing
        );

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_PUBLISHING, [
            'answer_id'   => $answerId,
            'deployment_id' => $deploymentId,
        ], $actorId);

        try {
            // The public site needs the question and the answer to exist before
            // it can publish anything; both calls are no-ops once their public
            // identifier is on file. A failure here is a failed deployment like
            // any other, so it is recorded before the exception propagates —
            // otherwise the deployment would stay 'executing' forever.
            try {
                $question = $this->ensurePublicQuestion($answer);
                $this->ensurePublicAnswer($answer, $question, $version);
            } catch (\Throwable $e) {
                $this->handlePublishFailure($deploymentId, $answerId, $answer['status'], [
                    'error_category'     => 'public_record_creation',
                    'safe_error_message' => $e->getMessage(),
                ]);
                throw $e;
            }

            $envelope = $this->buildPublishEnvelope($answer, $version, $idempotency);
            $result   = $this->publisher->publishAnswer($answer['uuid'], $envelope);

            if (!($result['success'] ?? false)) {
                $this->handlePublishFailure($deploymentId, $answerId, $answer['status'], $result);
                throw new \RuntimeException("Publication failed: " . ($result['safe_error_message'] ?? 'unknown'));
            }

            $this->handlePublishSuccess($deploymentId, $answerId, $result, $actorId);
            $this->markQuestionPublished($question, $result);
            return $result;

        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Publication failed:')) {
                $this->handlePublishFailure($deploymentId, $answerId, $answer['status'], [
                    'error_category' => 'exception', 'safe_error_message' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }

    /**
     * Retry a failed deployment.
     *
     * The original idempotency key is deliberately reused: the receiver treats a
     * repeat of an already-succeeded key as a replay and returns the first
     * result, so a retry can never produce a second public publication.
     *
     * @param array $deployment Row from reach_community_deployments.
     */
    public function retryDeployment(array $deployment, ?int $actorId = null): array
    {
        $deploymentId = (int) $deployment['id'];
        $answerId     = (int) $deployment['answer_id'];
        $attempts     = (int) ($deployment['attempt_count'] ?? 0);
        $maxAttempts  = (int) ($deployment['max_attempts'] ?? 5);

        if (($deployment['status'] ?? '') === 'succeeded') {
            return [
                'success'  => true,
                'outcome'  => 'already_succeeded',
                'skipped'  => true,
                'public_answer_id' => $deployment['public_answer_id'] ?? null,
                'canonical_url'    => $deployment['public_url'] ?? null,
            ];
        }

        if (! in_array($deployment['status'] ?? '', ['failed', 'retrying'], true)) {
            throw new \RuntimeException(
                "Deployment #{$deploymentId} is in status '{$deployment['status']}' and cannot be retried."
            );
        }

        if ($attempts >= $maxAttempts) {
            $this->deployModel->update($deploymentId, [
                'status'     => 'dead_letter',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            AuditLogger::record(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, [
                'deployment_id' => $deploymentId,
                'outcome'       => 'dead_letter',
                'attempts'      => $attempts,
            ], $actorId);

            return ['success' => false, 'outcome' => 'dead_letter', 'attempts' => $attempts];
        }

        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found for deployment #{$deploymentId}");
        }

        // The approval must still be intact — a retry must not publish content
        // that was edited or un-approved since the original attempt.
        if (! $this->approval->verifyApprovalForPublication($answer)) {
            $this->deployModel->update($deploymentId, [
                'status'              => 'dead_letter',
                'last_error'          => 'approval checksum no longer valid',
                'last_error_category' => 'validation_error',
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);
            return ['success' => false, 'outcome' => 'approval_invalidated'];
        }

        // Risk tier may have been raised since the original attempt — re-assert.
        try {
            $this->assertPublishableRiskState($answer);
        } catch (\RuntimeException $e) {
            $this->deployModel->update($deploymentId, [
                'status'              => 'dead_letter',
                'last_error'          => substr($e->getMessage(), 0, 500),
                'last_error_category' => 'validation_error',
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);
            return ['success' => false, 'outcome' => 'risk_blocked'];
        }

        $attempts++;
        $version = $this->answerRepo->getVersion($answerId, (int) $deployment['answer_version_number']);

        $this->deployModel->update($deploymentId, [
            'status'        => 'executing',
            'attempt_count' => $attempts,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // A retry may be the first attempt that gets far enough to create the
        // public records — the original attempt could have failed on either
        // create step — so re-run the same guarded sequence. A create failure
        // is retryable like any transport failure, so it feeds the same backoff
        // bookkeeping below rather than escaping the retry loop.
        try {
            $question = $this->ensurePublicQuestion($answer);
            $this->ensurePublicAnswer($answer, $question, $version);
        } catch (\Throwable $e) {
            return $this->recordRetryFailure($deploymentId, $attempts, $maxAttempts, [
                'success'            => false,
                'error_category'     => 'public_record_creation',
                'safe_error_message' => $e->getMessage(),
            ], $actorId);
        }

        $envelope = $this->buildPublishEnvelope($answer, $version, (string) $deployment['idempotency_key']);
        $result   = $this->publisher->publishAnswer($answer['uuid'], $envelope);

        if ($result['success'] ?? false) {
            $this->handlePublishSuccess($deploymentId, $answerId, $result, $actorId);
            $this->markQuestionPublished($question, $result);

            AuditLogger::record(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, [
                'deployment_id' => $deploymentId,
                'outcome'       => 'succeeded',
                'attempts'      => $attempts,
            ], $actorId);

            return array_merge($result, ['outcome' => 'succeeded', 'attempts' => $attempts]);
        }

        return $this->recordRetryFailure($deploymentId, $attempts, $maxAttempts, $result, $actorId);
    }

    /**
     * Book a failed retry attempt: schedule the next backoff window, or retire
     * the deployment to the dead-letter state once attempts are exhausted.
     */
    private function recordRetryFailure(
        int $deploymentId,
        int $attempts,
        int $maxAttempts,
        array $result,
        ?int $actorId
    ): array {
        $exhausted = $attempts >= $maxAttempts;

        $this->deployModel->update($deploymentId, [
            'status'              => $exhausted ? 'dead_letter' : 'retrying',
            'attempt_count'       => $attempts,
            'last_error'          => substr((string) ($result['safe_error_message'] ?? 'unknown'), 0, 500),
            'last_error_category' => $result['error_category'] ?? 'unknown',
            'next_retry_at'       => $exhausted ? null : date('Y-m-d H:i:s', time() + $this->backoffSeconds($attempts)),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, [
            'deployment_id' => $deploymentId,
            'outcome'       => $exhausted ? 'dead_letter' : 'retrying',
            'attempts'      => $attempts,
        ], $actorId);

        return array_merge($result, [
            'outcome'  => $exhausted ? 'dead_letter' : 'retrying',
            'attempts' => $attempts,
        ]);
    }

    /** Exponential backoff capped at one hour. */
    private function backoffSeconds(int $attempt): int
    {
        return (int) min(3600, 30 * (2 ** max(0, $attempt - 1)));
    }

    /**
     * Unpublish a published answer.
     */
    public function unpublish(int $answerId, string $reason, ?int $actorId = null): array
    {
        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found");
        }

        $idempotency = bin2hex(random_bytes(16));
        $envelope    = ['reason' => $reason, 'idempotency_key' => $idempotency];
        $result      = $this->publisher->unpublishAnswer($answer['uuid'], $envelope);

        if ($result['success'] ?? false) {
            $this->answerRepo->transitionStatus(
                $answerId,
                CommunityAnswerStatus::Published,
                CommunityAnswerStatus::Unpublished
            );
            $this->answerRepo->save(['id' => $answerId, 'publication_status' => 'unpublished']);
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_UNPUBLISHED, ['answer_id' => $answerId], $actorId);
        }

        return $result;
    }

    /**
     * Restore an unpublished/withdrawn answer.
     */
    public function restore(int $answerId, ?int $actorId = null): array
    {
        $answer      = $this->answerRepo->findById($answerId);
        $idempotency = bin2hex(random_bytes(16));
        $result      = $this->publisher->restoreAnswer($answer['uuid'], ['idempotency_key' => $idempotency]);

        if ($result['success'] ?? false) {
            $this->answerRepo->transitionStatus(
                $answerId,
                CommunityAnswerStatus::Restoring,
                CommunityAnswerStatus::Published
            );
            $this->answerRepo->save(['id' => $answerId, 'publication_status' => 'published']);
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_RESTORED, ['answer_id' => $answerId], $actorId);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Public-site record creation (must precede publish)
    // -------------------------------------------------------------------------

    /**
     * Make sure aicountly.com holds the question this answer belongs to.
     *
     * The stored public_question_id is the idempotency guard — the same role
     * reach_publication_deployments.public_content_id plays for blogs — so a
     * second publication of the same question never creates a second public
     * record.
     *
     * @return array The question row, refreshed with its public identifiers.
     */
    private function ensurePublicQuestion(array $answer): array
    {
        $question = $this->questionRepo->findById((int) $answer['question_id']);
        if ($question === null) {
            throw new \RuntimeException(
                "Publication failed: answer #{$answer['id']} has no question record."
            );
        }

        if (! empty($question['public_question_id'])) {
            return $question;
        }

        $payload  = self::buildQuestionPayload($question);
        $envelope = [
            'reach_answer_uuid' => $answer['uuid'],
            'question_uuid'     => $question['uuid'],
            'operation'         => 'create_question',
            'content_type'      => 'community_question',
            'idempotency_key'   => bin2hex(random_bytes(16)),
            'payload_checksum'  => $this->checksum($payload),
            'payload'           => $payload,
        ];

        $result = $this->publisher->createQuestion($envelope);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                'Publication failed: could not create the public question record — '
                . ($result['safe_error_message'] ?? 'unknown')
            );
        }

        $question['public_question_id']   = (string) ($result['public_question_id'] ?? '');
        $question['public_question_slug'] = (string) ($result['public_question_slug'] ?? $payload['slug']);

        $this->questionRepo->save([
            'id'                   => (int) $question['id'],
            'public_question_id'   => $question['public_question_id'],
            'public_question_slug' => $question['public_question_slug'],
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_PUBLISHING, [
            'question_id'        => (int) $question['id'],
            'public_question_id' => $question['public_question_id'],
            'operation'          => 'create_question',
        ]);

        return $question;
    }

    /**
     * Make sure aicountly.com holds a draft record for this answer, so the
     * publish call has something to flip live. Answers already on the public
     * site are updated instead, which is how a corrected version reaches the
     * site before being re-published.
     */
    private function ensurePublicAnswer(array $answer, array $question, ?array $version): void
    {
        $payload = [
            'body'             => $version['content'] ?? '',
            'excerpt'          => $version['excerpt'] ?? '',
            'ai_assisted'      => (bool) $answer['ai_assisted'],
            'human_reviewed'   => (bool) $answer['human_reviewed'],
            'approved_at'      => $version['approval_timestamp'] ?? date('c'),
            'answer_version'   => $answer['approved_version'],
            'robots_directive' => 'index,follow',
            'structured_data'  => [],
        ];

        $exists   = ! empty($answer['public_external_id']);
        $envelope = [
            'reach_answer_uuid'            => $answer['uuid'],
            'question_uuid'                => $question['uuid'],
            'public_question_id'           => $question['public_question_id'] ?? null,
            'operation'                    => $exists ? 'update_answer' : 'create_answer',
            'content_type'                 => 'community_answer',
            'reach_content_version_number' => $answer['approved_version'],
            'official_identity_slug'       => $answer['identity_slug'] ?? '',
            'idempotency_key'              => bin2hex(random_bytes(16)),
            'payload_checksum'             => $answer['approved_version_checksum'],
            'payload'                      => $payload,
        ];

        $result = $exists
            ? $this->publisher->updateAnswer($answer['uuid'], $envelope)
            : $this->publisher->createAnswer($envelope);

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                'Publication failed: could not create the public answer record — '
                . ($result['safe_error_message'] ?? 'unknown')
            );
        }

        if (! $exists && ! empty($result['public_answer_id'])) {
            $this->answerRepo->save([
                'id'                 => (int) $answer['id'],
                'public_external_id' => (string) $result['public_answer_id'],
            ]);
        }
    }

    /**
     * Record where the question is now readable. The answer's canonical URL is
     * an anchor into the question page, so the question inherits it minus the
     * fragment — that is the URL the inbox links out to.
     */
    private function markQuestionPublished(array $question, array $result): void
    {
        $canonical = (string) ($result['canonical_url'] ?? '');
        if ($canonical === '' || empty($question['id'])) {
            return;
        }

        $this->questionRepo->save([
            'id'           => (int) $question['id'],
            'public_url'   => explode('#', $canonical)[0],
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed> create_question payload per the Phase 5 contract.
     */
    public static function buildQuestionPayload(array $question): array
    {
        return [
            'title'            => (string) ($question['title'] ?? ''),
            'body'             => (string) ($question['body'] ?? ''),
            'category_slug'    => self::slugify((string) ($question['category'] ?? '')) ?: 'general',
            'tags'             => self::parsePgArray($question['tags'] ?? null),
            'slug'             => self::questionSlug($question),
            'language'         => (string) ($question['language'] ?? 'en'),
            'source_type'      => 'official_question',
            'robots_directive' => 'index,follow',
        ];
    }

    /**
     * A stable slug suggestion. The public site stays authoritative — whatever
     * it returns is what gets stored — but a deterministic suggestion keeps
     * retries from proposing a different URL each attempt.
     */
    public static function questionSlug(array $question): string
    {
        $base = self::slugify((string) ($question['title'] ?? ''));
        $base = $base === '' ? 'question' : substr($base, 0, 80);
        $uuid = (string) ($question['uuid'] ?? '');

        return $uuid === '' ? $base : $base . '-' . substr(str_replace('-', '', $uuid), 0, 8);
    }

    private static function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * Postgres TEXT[] columns come back as literals ('{}' / '{"a","b"}').
     *
     * @return list<string>
     */
    public static function parsePgArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $literal = trim((string) $value);
        if ($literal === '' || $literal === '{}') {
            return [];
        }
        $inner = trim($literal, '{}');
        if ($inner === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => trim(trim($item), '"'),
            str_getcsv($inner)
        ), static fn ($item) => $item !== ''));
    }

    private function checksum(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function buildPublishEnvelope(array $answer, ?array $version, string $idempotency): array
    {
        $content = $version['content'] ?? '';
        return [
            'reach_answer_uuid'           => $answer['uuid'],
            'question_uuid'               => $answer['question_uuid'] ?? null,
            'operation'                   => 'publish',
            'content_type'                => 'community_answer',
            'reach_content_version_number' => $answer['approved_version'],
            'official_identity_slug'      => $answer['identity_slug'] ?? '',
            'idempotency_key'             => $idempotency,
            'payload_checksum'            => $answer['approved_version_checksum'],
            'payload' => [
                'body'             => $content,
                'excerpt'          => $version['excerpt'] ?? '',
                'ai_assisted'      => (bool) $answer['ai_assisted'],
                'human_reviewed'   => (bool) $answer['human_reviewed'],
                'approved_at'      => $version['approval_timestamp'] ?? date('c'),
                'answer_version'   => $answer['approved_version'],
                'robots_directive' => 'index,follow',
                'structured_data'  => [],
            ],
        ];
    }

    private function handlePublishSuccess(int $deploymentId, int $answerId, array $result, ?int $actorId): void
    {
        $this->deployModel->update($deploymentId, [
            'status'           => 'succeeded',
            'public_answer_id' => $result['public_answer_id'] ?? null,
            'public_url'       => $result['canonical_url'] ?? null,
            'response_checksum' => $result['payload_checksum'] ?? null,
            'deployed_at'      => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->answerRepo->save([
            'id'                  => $answerId,
            'status'              => CommunityAnswerStatus::Published->value,
            'publication_status'  => 'published',
            'public_external_id'  => (string) ($result['public_answer_id'] ?? ''),
            'public_url'          => $result['canonical_url'] ?? null,
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_PUBLISHED, [
            'answer_id'    => $answerId,
            'canonical_url' => $result['canonical_url'] ?? null,
        ], $actorId);
    }

    private function handlePublishFailure(int $deploymentId, int $answerId, string $priorStatus, array $result): void
    {
        $deployment = $this->deployModel->find($deploymentId) ?? [];
        $attempts   = max(1, (int) ($deployment['attempt_count'] ?? 1));
        $maxAttempts = max(1, (int) ($deployment['max_attempts'] ?? 5));
        $exhausted  = $attempts >= $maxAttempts;

        // First failure must enter retrying + next_retry_at so community:schedule
        // retry sweep can pick it up (previously stuck forever as status=failed).
        $this->deployModel->update($deploymentId, [
            'status'              => $exhausted ? 'dead_letter' : 'retrying',
            'attempt_count'       => $attempts,
            'last_error'          => substr((string) ($result['safe_error_message'] ?? 'unknown'), 0, 500),
            'last_error_category' => $result['error_category'] ?? 'unknown',
            'next_retry_at'       => $exhausted ? null : date('Y-m-d H:i:s', time() + $this->backoffSeconds($attempts)),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        $this->answerRepo->transitionStatus(
            $answerId,
            CommunityAnswerStatus::Publishing,
            CommunityAnswerStatus::VerificationFailed
        );
    }
}
