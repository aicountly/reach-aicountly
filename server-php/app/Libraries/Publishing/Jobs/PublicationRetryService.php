<?php

namespace App\Libraries\Publishing\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Publishing\Connector\PublishingErrorClassifier;

/**
 * Phase 4 â€” Retry service with exponential backoff for failed publication deployments.
 *
 * Retries are automatic only for retryable error categories.
 * Non-retryable errors require human resolution.
 */
class PublicationRetryService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private PublishingErrorClassifier $classifier;

    private const MAX_ATTEMPTS = 5;
    private const BASE_DELAY_SECONDS = 60;
    private const MAX_DELAY_SECONDS = 3600;

    public function __construct()
    {
        $this->db         = \Config\Database::connect();
        $this->classifier = new PublishingErrorClassifier();
    }

    /**
     * Schedule a retry for a failed deployment.
     *
     * @throws \RuntimeException if error is not retryable or max attempts reached
     */
    public function scheduleRetry(int $deploymentId): void
    {
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)->get()->getRowArray();

        if (!$deployment) {
            throw new \RuntimeException("Deployment {$deploymentId} not found");
        }

        $errorCategory = $deployment['error_category'] ?? 'unknown_error';

        if (!$this->classifier->isRetryable($errorCategory)) {
            throw new \RuntimeException("Error category '{$errorCategory}' is not retryable");
        }

        $attemptCount = (int) $deployment['attempt_count'];

        if ($attemptCount >= self::MAX_ATTEMPTS) {
            $this->db->table('reach_publication_deployments')
                ->where('id', $deploymentId)
                ->update(['status' => 'blocked', 'updated_at' => date('Y-m-d H:i:s')]);

            AuditLogger::record('publishing.max_retries_reached', ['deployment_id' => $deploymentId]);
            return;
        }

        $delaySeconds = min(
            self::BASE_DELAY_SECONDS * (2 ** ($attemptCount - 1)),
            self::MAX_DELAY_SECONDS
        );

        $retryAt = date('Y-m-d H:i:s', time() + $delaySeconds);

        $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)
            ->update([
                'status'     => 'queued',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        // Re-enqueue with retry delay
        $this->db->table('reach_jobs')->insert([
            'type'         => 'publication',
            'payload'      => json_encode([
                'job_class'     => 'PublicationJob',
                'deployment_id' => $deploymentId,
                'is_retry'      => true,
            ]),
            'status'       => 'pending',
            'available_at' => $retryAt,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        AuditLogger::record('publishing.retry_scheduled', [
            'deployment_id'  => $deploymentId,
            'attempt'        => $attemptCount + 1,
            'delay_seconds'  => $delaySeconds,
            'retry_at'       => $retryAt,
        ]);
    }

    /**
     * Cancel a failed deployment (stops retries).
     */
    public function cancel(int $deploymentId, ?int $actorId = null): void
    {
        $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)
            ->update(['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);

        AuditLogger::record('publishing.cancelled', ['deployment_id' => $deploymentId], $actorId);
    }

    /**
     * Find deployments that need retry scheduling.
     */
    public function findDeploymentsDueForRetry(): array
    {
        return $this->db->table('reach_publication_deployments')
            ->where('status', 'failed')
            ->where('attempt_count <', self::MAX_ATTEMPTS)
            ->get()->getResultArray();
    }
}

