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
