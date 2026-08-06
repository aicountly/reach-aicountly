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
        private CommunityPublisherInterface              $publisher      = new CommunityPublicSitePublisher(),
        private ?CommunityPublicRecordProvisioner        $provisioner    = null,
    ) {
        // Allow factory injection for test environments
        $this->publisher   = CommunityPublisherFactory::create();
        $this->provisioner ??= new CommunityPublicRecordProvisioner($this->publisher);
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
            // The receiver publishes an answer that already exists; it does not
            // create one. Identity, question and answer must be upserted first
            // or the publish call 404s.
            $this->provisioner->ensureAnswerExists($answer, $version);

            $envelope = $this->buildPublishEnvelope($answer, $version, $idempotency);
            $result   = $this->publisher->publishAnswer($answer['uuid'], $envelope);

            if (!($result['success'] ?? false)) {
                $this->handlePublishFailure($deploymentId, $answerId, $answer['status'], $result);
                throw new \RuntimeException("Publication failed: " . ($result['safe_error_message'] ?? 'unknown'));
            }

            $this->handlePublishSuccess($deploymentId, $answerId, $result, $actorId);
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

        // Same ordering requirement as the first attempt — and if the original
        // failure was the missing public records, this is what fixes it.
        try {
            $this->provisioner->ensureAnswerExists($answer, $version);
        } catch (\RuntimeException $e) {
            $exhausted = $attempts >= $maxAttempts;
            $this->deployModel->update($deploymentId, [
                'status'              => $exhausted ? 'dead_letter' : 'retrying',
                'attempt_count'       => $attempts,
                'last_error'          => substr($e->getMessage(), 0, 500),
                'last_error_category' => 'provisioning_failed',
                'next_retry_at'       => $exhausted ? null : date('Y-m-d H:i:s', time() + $this->backoffSeconds($attempts)),
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);

            return [
                'success'  => false,
                'outcome'  => $exhausted ? 'dead_letter' : 'retrying',
                'attempts' => $attempts,
                'safe_error_message' => $e->getMessage(),
            ];
        }

        $envelope = $this->buildPublishEnvelope($answer, $version, (string) $deployment['idempotency_key']);
        $result   = $this->publisher->publishAnswer($answer['uuid'], $envelope);

        if ($result['success'] ?? false) {
            $this->handlePublishSuccess($deploymentId, $answerId, $result, $actorId);

            AuditLogger::record(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, [
                'deployment_id' => $deploymentId,
                'outcome'       => 'succeeded',
                'attempts'      => $attempts,
            ], $actorId);

            return array_merge($result, ['outcome' => 'succeeded', 'attempts' => $attempts]);
        }

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
    /**
     * Delay before the next retry of a failed deployment.
     *
     * Public and static so the schedule is verifiable without a database: it
     * decides how long a stuck publication waits, and the sweep only picks a
     * deployment back up once next_retry_at has passed. Doubling from 30s and
     * capped at an hour, so five attempts span roughly eight minutes rather
     * than hammering a public site that is already failing.
     */
    public static function backoffSeconds(int $attempt): int
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

    private function buildPublishEnvelope(array $answer, ?array $version, string $idempotency): array
    {
        $content = $version['content'] ?? '';
        return [
            'reach_answer_uuid'           => $answer['uuid'],
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
            // Clear the failure from the attempt this retry just superseded.
            // A succeeded row carrying last_error = "provisioning failed"
            // reads as a half-broken publication to whoever looks next.
            'last_error'          => null,
            'last_error_category' => null,
            'next_retry_at'       => null,
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
