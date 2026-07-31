<?php

namespace App\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Community\OfficialAnswerPublishingService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Models\CommunityDeploymentModel;

/**
 * Phase 5 — Community Deployment Retry Job.
 *
 * Job type key: reach.community_deployment_retry
 *
 * Payload: { "deployment_uuid": "string" }
 *
 * Retries a failed community publishing deployment, reusing the original
 * idempotency key so that a retry cannot produce a second public publication.
 * The job fails when the deployment is dead-lettered so the queue records a
 * failure rather than a false success.
 */
class CommunityDeploymentRetryJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $deploymentUuid = (string) ($payload['deployment_uuid'] ?? '');
        if ($deploymentUuid === '') {
            throw new \InvalidArgumentException('CommunityDeploymentRetryJob: deployment_uuid is required.');
        }

        $deployment = (new CommunityDeploymentModel())->findByUuid($deploymentUuid);
        if ($deployment === null) {
            throw new \RuntimeException("Deployment not found: {$deploymentUuid}");
        }

        $actorId = $ctx->enqueuedByUserId;
        $result  = (new OfficialAnswerPublishingService())->retryDeployment($deployment, $actorId);
        $outcome = (string) ($result['outcome'] ?? 'unknown');

        AuditLogger::record(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, [
            'deployment_uuid' => $deploymentUuid,
            'outcome'         => $outcome,
            'attempts'        => $result['attempts'] ?? null,
        ], $actorId);

        if (in_array($outcome, ['dead_letter', 'approval_invalidated'], true)) {
            throw new \RuntimeException(
                "Deployment {$deploymentUuid} could not be republished (outcome: {$outcome})."
            );
        }

        return [
            'ok'              => $outcome !== 'retrying',
            'deployment_uuid' => $deploymentUuid,
            'outcome'         => $outcome,
            'attempts'        => $result['attempts'] ?? null,
        ];
    }
}
