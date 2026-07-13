<?php

namespace App\Libraries\Publishing\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use App\Libraries\Publishing\Seo\PublicationReadinessAggregator;

/**
 * Phase 4 â€” Publication deployment service.
 *
 * Orchestrates the creation and progression of publication deployments.
 * Uses the Phase 0 job queue for async operations.
 * Human approval is mandatory; this service never approves content.
 */
class PublicationDeploymentService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private PublishingErrorClassifier $classifier;

    public function __construct()
    {
        $this->db         = \Config\Database::connect();
        $this->classifier = new PublishingErrorClassifier();
    }

    /**
     * Enqueue a publication job for a content item.
     *
     * @throws \RuntimeException if readiness check fails or content not approved
     */
    public function enqueuePublication(
        int $contentItemId,
        int $contentVersionId,
        string $connectionKey = 'aicountly_com',
        string $operation = 'publish',
        ?string $scheduledAt = null,
        ?int $createdBy = null
    ): int {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)->get()->getRowArray();

        if (!$item || $item['approval_status'] !== 'approved') {
            throw new \RuntimeException('Content must be human-approved before publishing');
        }

        $connection = $this->db->table('reach_publication_connections')
            ->where('connection_key', $connectionKey)
            ->where('enabled', true)
            ->get()->getRowArray();

        if (!$connection) {
            throw new \RuntimeException("Publication connection '{$connectionKey}' not found or disabled");
        }

        // Readiness gate
        $aggregator = new PublicationReadinessAggregator();
        $readiness  = $aggregator->evaluate($contentItemId, $item['content_type']);

        if (!$readiness['ready']) {
            throw new \RuntimeException(
                'Content is not ready for publication: ' . implode('; ', $readiness['blocking'])
            );
        }

        $idempotencyKey = "reach-{$contentItemId}-v{$contentVersionId}-{$operation}-" . time();

        $this->db->table('reach_publication_deployments')->insert([
            'content_item_id'    => $contentItemId,
            'content_version_id' => $contentVersionId,
            'connection_id'      => $connection['id'],
            'operation'          => $operation,
            'status'             => 'queued',
            'idempotency_key'    => $idempotencyKey,
            'scheduled_at'       => $scheduledAt,
            'created_by'         => $createdBy,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $deploymentId = $this->db->insertID();

        // Enqueue to Phase 0 job queue
        $this->db->table('reach_jobs')->insert([
            'type'       => 'publication',
            'payload'    => json_encode([
                'job_class'     => 'PublicationJob',
                'deployment_id' => $deploymentId,
            ]),
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLogger::record('publishing.queued', [
            'content_item_id' => $contentItemId,
            'deployment_id'   => $deploymentId,
            'operation'       => $operation,
        ], $createdBy);

        return $deploymentId;
    }

    /**
     * Process a deployment â€” called by the job worker.
     */
    public function processDeployment(int $deploymentId): void
    {
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)->get()->getRowArray();

        if (!$deployment) {
            throw new \RuntimeException("Deployment {$deploymentId} not found");
        }

        if (!in_array($deployment['status'], ['queued', 'sending'], true)) {
            return; // Already processed or cancelled
        }

        $this->updateDeploymentStatus($deploymentId, 'sending');

        $attemptNumber = ((int) $deployment['attempt_count']) + 1;

        $this->db->table('reach_publication_attempts')->insert([
            'deployment_id'  => $deploymentId,
            'attempt_number' => $attemptNumber,
            'status'         => 'sending',
            'started_at'     => date('Y-m-d H:i:s'),
        ]);

        $attemptId = $this->db->insertID();
        $this->updateDeploymentStatus($deploymentId, 'sending', ['latest_attempt_id' => $attemptId, 'attempt_count' => $attemptNumber]);

        $publisher = PublicSitePublisherFactory::make();

        try {
            $payload  = $this->buildPublishingEnvelope($deployment);
            $response = $publisher->createDraft($payload);

            if ($response['success']) {
                $this->onSuccess($deploymentId, $attemptId, $response);
            } else {
                $this->onFailure($deploymentId, $attemptId, $response['error_category'] ?? 'unknown_error', $response['safe_error_message'] ?? '');
            }
        } catch (\Throwable $e) {
            $category = $this->classifier->classifyException($e);
            $this->onFailure($deploymentId, $attemptId, $category, 'Exception during publishing');
        }
    }

    private function onSuccess(int $deploymentId, int $attemptId, array $response): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('reach_publication_attempts')->where('id', $attemptId)->update([
            'status'       => 'accepted',
            'http_status'  => 200,
            'completed_at' => $now,
            'duration_ms'  => 0,
        ]);

        $this->db->table('reach_publication_deployments')->where('id', $deploymentId)->update([
            'status'             => 'accepted',
            'public_content_id'  => $response['public_content_id'] ?? null,
            'public_content_uuid'=> $response['public_content_uuid'] ?? null,
            'canonical_url'      => $response['canonical_url'] ?? null,
            'payload_checksum'   => $response['payload_checksum'] ?? null,
            'completed_at'       => $now,
            'updated_at'         => $now,
        ]);

        AuditLogger::record('publishing.accepted', [
            'deployment_id'    => $deploymentId,
            'public_content_id'=> $response['public_content_id'] ?? null,
        ]);
    }

    private function onFailure(int $deploymentId, int $attemptId, string $errorCategory, string $message): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('reach_publication_attempts')->where('id', $attemptId)->update([
            'status'        => 'failed',
            'error_category'=> $errorCategory,
            'redacted_error'=> $message,
            'completed_at'  => $now,
        ]);

        $this->db->table('reach_publication_deployments')->where('id', $deploymentId)->update([
            'status'         => 'failed',
            'error_category' => $errorCategory,
            'redacted_error' => $message,
            'updated_at'     => $now,
        ]);

        AuditLogger::record('publishing.failed', [
            'deployment_id'  => $deploymentId,
            'error_category' => $errorCategory,
        ]);
    }

    private function updateDeploymentStatus(int $deploymentId, string $status, array $extra = []): void
    {
        $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)
            ->update(array_merge(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $extra));
    }

    private function buildPublishingEnvelope(array $deployment): array
    {
        return [
            'api_version'                 => 1,
            'operation'                   => 'create_draft',
            'reach_content_id'            => $deployment['content_item_id'],
            'reach_content_uuid'          => '',
            'reach_content_version_id'    => $deployment['content_version_id'],
            'reach_content_version_number'=> 1,
            'content_type'                => 'blog',
            'idempotency_key'             => $deployment['idempotency_key'],
            'request_id'                  => $deployment['request_id'] ?? '',
            'timestamp'                   => time(),
            'nonce'                       => bin2hex(random_bytes(16)),
            'payload_checksum'            => $deployment['payload_checksum'] ?? '',
            'publication_target'          => 'aicountly_com_blog',
            'payload'                     => [],
        ];
    }
}

