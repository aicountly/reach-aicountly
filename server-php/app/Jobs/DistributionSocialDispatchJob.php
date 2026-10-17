<?php

namespace App\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\SocialPublisherService;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\CampaignDispatchModel;
use App\Models\Distribution\CampaignOperationalMetricsModel;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * distribution.dispatch.social (queue `distribution`).
 *
 * Payload: {dispatch_id, variant_id, tenant_id} from campaign fan-out, or
 * {post_id, tenant_id} for a single approved social post. The provider's
 * answer decides the status; a failure is recorded, not retried blindly
 * (the handler returns the failed result instead of throwing).
 */
class DistributionSocialDispatchJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $service = new SocialPublisherService(
            new CampaignDispatchModel(),
            new CampaignDeliveryAttemptModel(),
            new CampaignOperationalMetricsModel(),
            new AuditLogger(),
        );
        $tenantId = (int) ($payload['tenant_id'] ?? 0);

        if (! empty($payload['dispatch_id']) && ! empty($payload['variant_id'])) {
            return $service->dispatchVariant((int) $payload['dispatch_id'], (int) $payload['variant_id'], $tenantId);
        }
        if (! empty($payload['post_id'])) {
            try {
                return $service->dispatch((int) $payload['post_id'], $tenantId, $ctx->enqueuedByUserId);
            } catch (\Throwable $e) {
                return ['status' => 'failed', 'post_id' => (int) $payload['post_id'], 'error' => $e->getMessage()];
            }
        }

        throw new \InvalidArgumentException('social dispatch job needs {dispatch_id, variant_id} or {post_id}');
    }
}
