<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\JobService;

/**
 * Orchestrates multi-channel campaign dispatch.
 *
 * When a campaign has channel variants (social, email, whatsapp, sms),
 * this service fans out one dispatch job per channel, each independently
 * tracked in `reach_campaign_dispatches`.
 */
class CampaignDispatchOrchestratorService
{
    public function __construct(
        private readonly JobService  $jobs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Fan out dispatch jobs for all active channel variants of a campaign version.
     */
    public function fanOut(int $campaignId, int $campaignVersionId, int $tenantId, ?int $actorId): array
    {
        $db       = \Config\Database::connect();
        $campaign = $db->table('reach_campaigns')->where('id', $campaignId)->get()->getRowArray();

        if ($campaign === null || (int) ($campaign['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('Campaign not found.', 404);
        }

        if (!in_array($campaign['status'] ?? '', ['approved', 'scheduled'], true)) {
            throw new \RuntimeException('Campaign is not in a dispatchable state.', 409);
        }

        $variants = $db->table('reach_campaign_channel_variants')
            ->where('campaign_version_id', $campaignVersionId)
            ->where('validation_status', 'passed')
            ->get()->getResultArray();

        if (empty($variants)) {
            throw new \RuntimeException('No validated channel variants found for this campaign version.', 422);
        }

        $jobTypeMap = [
            'social'   => DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL,
            'email'    => DistributionJobTypes::CAMPAIGN_DISPATCH_EMAIL,
            'whatsapp' => DistributionJobTypes::CAMPAIGN_DISPATCH_WA,
            'sms'      => DistributionJobTypes::CAMPAIGN_DISPATCH_SMS,
        ];

        $dispatched = [];
        foreach ($variants as $variant) {
            $channel = $variant['channel'];
            if (!isset($jobTypeMap[$channel])) {
                continue;
            }

            $idempotencyKey = 'orchestrator:' . $campaignId . ':' . $channel . ':' . $campaignVersionId;

            $dispatchId = $db->table('reach_campaign_dispatches')->insert([
                'uuid'            => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                'campaign_id'     => $campaignId,
                'tenant_id'       => $tenantId,
                'channel'         => $channel,
                'status'          => 'pending',
                'idempotency_key' => $idempotencyKey,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            $insertedId = $db->insertID();
            $jobId = $this->jobs->enqueue(
                $jobTypeMap[$channel],
                [
                    'campaign_id'   => $campaignId,
                    'dispatch_id'   => $insertedId,
                    'variant_id'    => $variant['id'],
                    'tenant_id'     => $tenantId,
                ],
                ['queue' => 'distribution', 'max_attempts' => 3, 'priority' => 60],
            );

            $dispatched[] = ['channel' => $channel, 'job_id' => $jobId];
        }

        $db->table('reach_campaigns')->where('id', $campaignId)->update([
            'status'     => 'dispatching',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_DISPATCHING, [
            'campaign_id'  => $campaignId,
            'version_id'   => $campaignVersionId,
            'channels'     => array_column($dispatched, 'channel'),
            'jobs_enqueued'=> count($dispatched),
        ], $actorId);

        return ['dispatched_channels' => $dispatched, 'total' => count($dispatched)];
    }
}
