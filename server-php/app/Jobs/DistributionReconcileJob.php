<?php

namespace App\Jobs;

use App\Libraries\Distribution\ChannelDispatchService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * distribution.campaign.reconcile (queue `distribution`).
 *
 * Payload: {dispatch_id}. Repairs a dispatch from local records (stuck
 * `sending` attempts → failed/unknown, requeue remaining recipients, or
 * finalise). It does not poll providers for delivery reports — none of the
 * current gateways expose a status API Reach uses.
 */
class DistributionReconcileJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $dispatchId = (int) ($payload['dispatch_id'] ?? 0);
        if ($dispatchId <= 0) {
            throw new \InvalidArgumentException('reconcile job needs dispatch_id');
        }
        $row = \Config\Database::connect()->table('reach_campaign_dispatches')->where('id', $dispatchId)->get()->getRowArray();
        if ($row === null) {
            return ['status' => 'noop', 'reason' => 'dispatch not found'];
        }
        if ($row['channel'] === 'social') {
            return ['status' => 'noop', 'reason' => 'social dispatches settle when the publish job runs'];
        }

        return (new ChannelDispatchService())->reconcile($dispatchId);
    }
}
