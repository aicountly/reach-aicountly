<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\JobService;

/**
 * Orchestrates multi-channel campaign dispatch.
 *
 * fanOut() starts one dispatch per validated channel variant of an approved
 * campaign version (email / SMS / WhatsApp through ChannelDispatchService,
 * social through the distribution.dispatch.social job). dispatchCampaign()
 * is what the scheduled-send job runs: it starts every approved channel
 * campaign linked to the campaign, then fans out its latest approved version.
 *
 * Dispatch rows are created `queued` (the value the table's CHECK and
 * App\Enums\DispatchStatus allow — the earlier 'pending' violated both).
 */
class CampaignDispatchOrchestratorService
{
    private ChannelDispatchService $channels;

    public function __construct(
        private readonly JobService  $jobs,
        private readonly AuditLogger $audit,
        ?ChannelDispatchService $channels = null,
    ) {
        $this->channels = $channels ?? new ChannelDispatchService($jobs, null, $audit);
    }

    /**
     * Fan out dispatches for all validated channel variants of a campaign version.
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
            ->where('validation_status', 'valid')
            ->get()->getResultArray();

        if (empty($variants)) {
            throw new \RuntimeException('No validated channel variants found for this campaign version.', 422);
        }

        $dispatched = [];
        $errors     = [];
        foreach ($variants as $variant) {
            $channel = (string) $variant['channel'];
            try {
                if ($channel === 'social') {
                    $dispatched[] = $this->queueSocialVariant($campaignId, $campaignVersionId, (int) $variant['id'], $tenantId, $actorId);
                    continue;
                }
                $result = $this->channels->start(ChannelDispatchService::SOURCE_VARIANT, (int) $variant['id'], $tenantId, $actorId);
                $dispatched[] = ['channel' => $channel, 'variant_id' => (int) $variant['id']] + $result;
            } catch (\Throwable $e) {
                // One channel failing to start must not hide the others, nor be reported as dispatched.
                $errors[] = ['channel' => $channel, 'variant_id' => (int) $variant['id'], 'error' => $e->getMessage()];
            }
        }

        if ($dispatched !== []) {
            $db->table('reach_campaigns')->where('id', $campaignId)->whereIn('status', ['approved', 'scheduled'])->update([
                'status'     => 'dispatching',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_DISPATCHING, [
            'campaign_id'  => $campaignId,
            'version_id'   => $campaignVersionId,
            'channels'     => array_column($dispatched, 'channel'),
            'started'      => count($dispatched),
            'errors'       => $errors,
        ], $actorId);

        return ['dispatched_channels' => $dispatched, 'errors' => $errors, 'total' => count($dispatched)];
    }

    /**
     * Start everything a campaign is due to send: linked email / WhatsApp /
     * SMS campaigns that are approved or scheduled, plus the latest approved
     * version's variants.
     */
    public function dispatchCampaign(int $campaignId, int $tenantId, ?int $actorId): array
    {
        $db      = \Config\Database::connect();
        $started = [];
        $errors  = [];

        $sources = [];
        foreach ($db->table('reach_email_campaigns')->select('id')->where('campaign_id', $campaignId)->whereIn('status', ['approved', 'scheduled'])->get()->getResultArray() as $row) {
            $sources[] = [ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, (int) $row['id']];
        }
        foreach ($db->table('reach_whatsapp_campaigns')->select('id')->where('campaign_id', $campaignId)->whereIn('status', ['approved', 'scheduled'])->get()->getResultArray() as $row) {
            $sources[] = [ChannelDispatchService::SOURCE_WHATSAPP_CAMPAIGN, (int) $row['id']];
        }
        if ($db->table('reach_sms_campaigns')->where('campaign_id', $campaignId)->countAllResults() > 0) {
            $sources[] = [ChannelDispatchService::SOURCE_SMS_CAMPAIGN, $campaignId];
        }

        foreach ($sources as [$type, $id]) {
            try {
                $started[] = ['source' => $type . ':' . $id] + $this->channels->start($type, $id, $tenantId, $actorId);
            } catch (\Throwable $e) {
                $errors[] = ['source' => $type . ':' . $id, 'error' => $e->getMessage()];
            }
        }

        $version = $db->table('reach_campaign_versions')->where('campaign_id', $campaignId)->where('approved_at IS NOT NULL', null, false)
            ->orderBy('version_number', 'DESC')->get()->getRowArray();
        if ($version !== null) {
            try {
                $fan = $this->fanOut($campaignId, (int) $version['id'], $tenantId, $actorId);
                $started = array_merge($started, $fan['dispatched_channels']);
                $errors  = array_merge($errors, $fan['errors']);
            } catch (\Throwable $e) {
                $errors[] = ['source' => 'version:' . $version['id'], 'error' => $e->getMessage()];
            }
        }

        if ($started === [] && $errors !== []) {
            $db->table('reach_campaigns')->where('id', $campaignId)->whereIn('status', ['approved', 'scheduled'])
                ->update(['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')]);
        }

        return ['campaign_id' => $campaignId, 'started' => $started, 'errors' => $errors];
    }

    private function queueSocialVariant(int $campaignId, int $versionId, int $variantId, int $tenantId, ?int $actorId): array
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $key = 'orchestrator:' . $campaignId . ':social:' . $versionId . ':' . $variantId;
        $existing = $db->table('reach_campaign_dispatches')->where('idempotency_key', $key)->get()->getRowArray();
        if ($existing !== null) {
            return ['channel' => 'social', 'variant_id' => $variantId, 'status' => 'already_dispatched', 'dispatch_id' => (int) $existing['id']];
        }
        $db->table('reach_campaign_dispatches')->insert([
            'campaign_id'         => $campaignId,
            'campaign_version_id' => $versionId,
            'tenant_id'           => $tenantId,
            'channel'             => 'social',
            'status'              => 'queued',
            'idempotency_key'     => $key,
            'total_recipients'    => 1,
            'source_type'         => ChannelDispatchService::SOURCE_VARIANT,
            'source_id'           => $variantId,
            'created_by'          => $actorId,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $dispatchId = (int) $db->insertID();
        $jobId = $this->jobs->enqueue(
            DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL,
            ['dispatch_id' => $dispatchId, 'variant_id' => $variantId, 'tenant_id' => $tenantId],
            ['queue' => ChannelDispatchService::QUEUE, 'max_attempts' => 3, 'priority' => 60],
        );

        return ['channel' => 'social', 'variant_id' => $variantId, 'status' => 'queued', 'dispatch_id' => $dispatchId, 'job_id' => $jobId];
    }
}
