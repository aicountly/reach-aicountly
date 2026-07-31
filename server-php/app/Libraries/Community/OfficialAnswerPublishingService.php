<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\AuditLogger;
use App\Models\CommunityDeploymentModel;

/**
 * Orchestrates the secure publishing of approved official answers to aicountly-com.
 *
 * Enforces:
 *   - Pre-publication approval checksum verification
 *   - Idempotent deployment records
 *   - HMAC-signed API calls via CommunityPublicSitePublisher
 *   - Status transition after successful/failed deployment
 */
class OfficialAnswerPublishingService
{
    public function __construct(
        private readonly OfficialAnswerRepository     $answerRepo  = new OfficialAnswerRepository(),
        private readonly OfficialAnswerApprovalService $approval   = new OfficialAnswerApprovalService(),
        private readonly CommunityDeploymentModel      $deployModel = new CommunityDeploymentModel(),
        private CommunityPublisherInterface            $publisher   = new CommunityPublicSitePublisher()
    ) {
        // Allow factory injection for test environments
        $this->publisher = CommunityPublisherFactory::create();
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

        $attempts++;
        $version = $this->answerRepo->getVersion($answerId, (int) $deployment['answer_version_number']);

        $this->deployModel->update($deploymentId, [
            'status'        => 'executing',
            'attempt_count' => $attempts,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

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
        $this->deployModel->update($deploymentId, [
            'status'            => 'failed',
            'last_error'        => $result['safe_error_message'] ?? 'unknown',
            'last_error_category' => $result['error_category'] ?? 'unknown',
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $this->answerRepo->transitionStatus(
            $answerId,
            CommunityAnswerStatus::Publishing,
            CommunityAnswerStatus::VerificationFailed
        );
    }
}
