<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Enums\RefreshWorkflowStatus;
use App\Libraries\AuditLogger;
use App\Models\Refresh\RefreshPublicationLinkModel;
use App\Models\Refresh\RefreshWorkflowModel;
use RuntimeException;

/**
 * Queues approved refresh workflows for publication by creating a publication
 * link record with an idempotency key. The actual publication is performed by
 * content-type-specific publishers (Blog, KB, Community, Video, Campaign).
 *
 * Publication safety rules:
 * - Workflow must be in Approved status
 * - Idempotency key prevents duplicate deliveries
 * - All publication attempts are traceable via publication link
 */
class RefreshPublicationService
{
    public function __construct(
        private RefreshWorkflowModel        $workflowModel,
        private RefreshPublicationLinkModel $linkModel,
        private AuditLogger                 $auditLogger,
    ) {}

    public function queue(int $workflowId, int $actorId): array
    {
        $workflow = $this->workflowModel->find($workflowId);
        if (! $workflow) {
            throw new RuntimeException("Workflow {$workflowId} not found");
        }
        if ($workflow['status'] !== RefreshWorkflowStatus::Approved->value) {
            throw new RuntimeException("Workflow {$workflowId} must be in approved status to queue for publication");
        }

        $idempotencyKey = sprintf('refresh-pub-%d-%s', $workflowId, hash('xxh3', (string) $workflowId . (string) time()));

        $existing = $this->linkModel->findByIdempotencyKey($idempotencyKey);
        if ($existing) {
            return $existing;
        }

        $linkId = $this->linkModel->insert([
            'workflow_id'     => $workflowId,
            'idempotency_key' => $idempotencyKey,
            'delivery_status' => 'queued',
        ]);

        $this->auditLogger->log(
            userId:     $actorId,
            action:     AuditLogger::REFRESH_PUBLISHED,
            entityType: 'refresh_workflow',
            entityId:   $workflowId,
            extra:      ['idempotency_key' => $idempotencyKey],
        );

        return $this->linkModel->find($linkId);
    }

    public function markDelivered(int $linkId, int $publicationAttemptId = null): void
    {
        $this->linkModel->update($linkId, [
            'delivery_status'        => 'delivered',
            'published_at'           => date('Y-m-d H:i:s'),
            'publication_attempt_id' => $publicationAttemptId,
        ]);
    }

    public function markFailed(int $linkId, string $reason = ''): void
    {
        $link = $this->linkModel->find($linkId);
        if (! $link) throw new RuntimeException("Publication link {$linkId} not found");

        $this->linkModel->update($linkId, [
            'delivery_status' => 'failed',
            'retry_count'     => (int) $link['retry_count'] + 1,
        ]);
    }
}
