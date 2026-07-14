<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Enums\CampaignStatus;
use App\Models\Distribution\CampaignVersionModel;

class CampaignVersionService
{
    public function __construct(
        private readonly CampaignVersionModel $model,
        private readonly AuditLogger          $audit,
    ) {}

    public function create(int $campaignId, array $data, ?int $actorId): array
    {
        $versionNumber = $this->model->nextVersionNumber($campaignId);
        $contentHash   = hash('sha256', json_encode($data['content'] ?? []));

        $id = $this->model->insert([
            'campaign_id'    => $campaignId,
            'version_number' => $versionNumber,
            'content_hash'   => $contentHash,
            'created_by'     => $actorId,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $version = $this->model->find($id);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_VERSION_CREATED, [
            'campaign_id'    => $campaignId,
            'version_id'     => $id,
            'version_number' => $versionNumber,
        ], $actorId);

        return $version;
    }

    public function submit(int $versionId, ?int $actorId): array
    {
        $version = $this->model->find($versionId);
        if ($version === null) {
            throw new \RuntimeException('Version not found.', 404);
        }
        if ($version['submitted_at'] !== null) {
            return $version; // Already submitted — idempotent
        }

        $this->model->update($versionId, [
            'submitted_by' => $actorId,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->updateCampaignStatus((int) $version['campaign_id'], CampaignStatus::ReadyForReview);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_VERSION_SUBMITTED, [
            'version_id'  => $versionId,
            'campaign_id' => $version['campaign_id'],
        ], $actorId);

        return $this->model->find($versionId);
    }

    public function approve(int $versionId, ?int $actorId, ?int $submittedBy = null): array
    {
        $version = $this->model->find($versionId);
        if ($version === null) {
            throw new \RuntimeException('Version not found.', 404);
        }

        // Self-approval prevention
        if ($submittedBy !== null && $actorId === $submittedBy) {
            throw new \RuntimeException('Self-approval is not permitted.', 403);
        }

        $this->model->update($versionId, [
            'approved_by' => $actorId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        $this->updateCampaignStatus((int) $version['campaign_id'], CampaignStatus::Approved);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_VERSION_APPROVED, [
            'version_id'  => $versionId,
            'campaign_id' => $version['campaign_id'],
        ], $actorId);

        return $this->model->find($versionId);
    }

    public function reject(int $versionId, string $reason, ?int $actorId): array
    {
        $version = $this->model->find($versionId);
        if ($version === null) {
            throw new \RuntimeException('Version not found.', 404);
        }

        $this->model->update($versionId, [
            'rejected_by'      => $actorId,
            'rejected_at'      => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
        ]);

        $this->updateCampaignStatus((int) $version['campaign_id'], CampaignStatus::Rejected);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_VERSION_REJECTED, [
            'version_id'  => $versionId,
            'reason'      => $reason,
        ], $actorId);

        return $this->model->find($versionId);
    }

    public function requestChanges(int $versionId, string $notes, ?int $actorId): array
    {
        $version = $this->model->find($versionId);
        if ($version === null) {
            throw new \RuntimeException('Version not found.', 404);
        }

        $this->model->update($versionId, [
            'rejection_reason' => $notes,
            'rejected_by'      => $actorId,
            'rejected_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->updateCampaignStatus((int) $version['campaign_id'], CampaignStatus::ChangesRequested);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_CHANGES_REQUESTED, [
            'version_id' => $versionId,
            'notes'      => $notes,
        ], $actorId);

        return $this->model->find($versionId);
    }

    public function listForCampaign(int $campaignId): array
    {
        return $this->model->findByCampaign($campaignId);
    }

    private function updateCampaignStatus(int $campaignId, CampaignStatus $status): void
    {
        $db = \Config\Database::connect();
        $db->table('reach_campaigns')->where('id', $campaignId)->update([
            'status'     => $status->value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
