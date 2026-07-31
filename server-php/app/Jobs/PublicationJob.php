<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;

/**
 * Executes a publication deployment row via PublicationDeploymentService.
 */
class PublicationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $deploymentId = (int) ($payload['deployment_id'] ?? 0);
        if ($deploymentId <= 0) {
            throw new \InvalidArgumentException('PublicationJob requires payload.deployment_id');
        }

        $service = new PublicationDeploymentService();
        $service->processDeployment($deploymentId);

        return [
            'deployment_id' => $deploymentId,
            'processed'     => true,
            'is_retry'      => (bool) ($payload['is_retry'] ?? false),
            'attempt'       => $ctx->attempts,
            'worker_id'     => $ctx->workerId,
        ];
    }
}
