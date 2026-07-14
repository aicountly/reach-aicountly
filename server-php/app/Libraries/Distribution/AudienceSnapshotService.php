<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Models\Distribution\AudienceSnapshotModel;
use App\Models\Distribution\AudienceRecipientModel;
use App\Models\Distribution\ChannelSuppressionModel;

class AudienceSnapshotService
{
    public function __construct(
        private readonly AudienceSnapshotModel   $snapshotModel,
        private readonly AudienceRecipientModel  $recipientModel,
        private readonly ChannelSuppressionModel $suppressionModel,
        private readonly AuditLogger             $audit,
    ) {}

    public function createSnapshot(int $campaignId, int $tenantId, string $channel, ?int $versionId, ?array $criteria, ?int $actorId): array
    {
        $snapshotId = $this->snapshotModel->insert([
            'campaign_id'         => $campaignId,
            'campaign_version_id' => $versionId,
            'tenant_id'           => $tenantId,
            'channel'             => $channel,
            'snapshot_criteria'   => $criteria ? json_encode($criteria) : null,
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_SNAPSHOT_CREATED, [
            'snapshot_id' => $snapshotId,
            'campaign_id' => $campaignId,
            'channel'     => $channel,
        ], $actorId);

        return $this->snapshotModel->find($snapshotId);
    }

    public function freeze(int $snapshotId, int $tenantId, ?int $actorId): array
    {
        $snapshot = $this->snapshotModel->find($snapshotId);
        if ($snapshot === null || (int) $snapshot['tenant_id'] !== $tenantId) {
            throw new \RuntimeException('Snapshot not found.', 404);
        }

        if ($snapshot['frozen_at'] !== null) {
            return $snapshot; // Already frozen — idempotent
        }

        // Count eligible recipients
        $eligible   = $this->recipientModel->countEligible($snapshotId);
        $total      = $this->recipientModel->where('snapshot_id', $snapshotId)->countAllResults();
        $suppressed = $total - $eligible;

        $this->snapshotModel->update($snapshotId, [
            'frozen_at'       => date('Y-m-d H:i:s'),
            'frozen_by'       => $actorId,
            'recipient_count' => $total,
            'eligible_count'  => $eligible,
            'suppressed_count'=> $suppressed,
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_SNAPSHOT_FROZEN, [
            'snapshot_id'     => $snapshotId,
            'recipient_count' => $total,
            'eligible_count'  => $eligible,
        ], $actorId);

        return $this->snapshotModel->find($snapshotId);
    }

    public function get(int $campaignId, int $tenantId, ?string $channel = null): ?array
    {
        $snapshot = $this->snapshotModel->findByCampaign($campaignId, $channel);
        if ($snapshot === null || (int) $snapshot['tenant_id'] !== $tenantId) {
            return null;
        }
        return $snapshot;
    }

    public function isFrozen(int $snapshotId): bool
    {
        return $this->snapshotModel->isFrozen($snapshotId);
    }
}
