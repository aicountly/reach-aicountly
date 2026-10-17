<?php

namespace App\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\CampaignDispatchOrchestratorService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\JobService;

/**
 * distribution.campaign.schedule (queue `distribution`), enqueued by
 * CampaignScheduleService for the scheduled send time.
 *
 * Payload: {campaign_id, tenant_id}. Starts every approved channel send linked
 * to the campaign. A cancelled campaign is left alone.
 */
class DistributionCampaignScheduleJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $tenantId   = (int) ($payload['tenant_id'] ?? 0);
        $campaign   = \Config\Database::connect()->table('reach_campaigns')->where('id', $campaignId)->get()->getRowArray();
        if ($campaign === null) {
            return ['status' => 'noop', 'reason' => 'campaign not found'];
        }
        if (! in_array($campaign['status'], ['scheduled', 'approved'], true)) {
            return ['status' => 'noop', 'reason' => 'campaign is ' . $campaign['status']];
        }

        $jobs = new JobService();
        return (new CampaignDispatchOrchestratorService($jobs, new AuditLogger()))
            ->dispatchCampaign($campaignId, $tenantId, $ctx->enqueuedByUserId);
    }
}
