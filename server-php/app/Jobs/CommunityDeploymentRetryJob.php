<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Community\OfficialAnswerPublishingService;
use App\Models\CommunityDeploymentModel;
use App\Libraries\AuditLogger;

/**
 * Phase 5 — Community Deployment Retry Job.
 *
 * Job type key: reach.community_deployment_retry
 *
 * Payload: { "deployment_uuid": "string" }
 *
 * Retries a failed community publishing deployment up to the configured
 * max attempt limit.
 */
class CommunityDeploymentRetryJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $deploymentUuid = $payload['deployment_uuid'] ?? '';
        if (empty($deploymentUuid)) {
            throw new \InvalidArgumentException('CommunityDeploymentRetryJob: deployment_uuid is required.');
        }

        $model      = new CommunityDeploymentModel();
        $deployment = $model->findByUuid($deploymentUuid);

        if (!$deployment) {
            throw new \RuntimeException("Deployment not found: {$deploymentUuid}");
        }

        $pubSvc = new OfficialAnswerPublishingService();
        $result = $pubSvc->retryDeployment($deployment);

        AuditLogger::log(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, [
            'deployment_uuid' => $deploymentUuid,
            'outcome'         => $result['outcome'] ?? 'unknown',
        ]);

        return ['ok' => true, 'deployment_uuid' => $deploymentUuid];
    }
}
