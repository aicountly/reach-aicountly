<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Models\CommunityDeploymentModel;
use Config\Services;

/**
 * Phase 4 — Community Deployment Retry Sweep Job.
 *
 * Job type key: reach.community_deployment_retry_sweep
 *
 * Payload: {} (no parameters — scans for everything currently due)
 *
 * CommunityDeploymentRetryJob only knows how to retry one named deployment;
 * nothing previously enqueued it for the deployments CommunityDeploymentModel
 * already tracks as due (`listRetryDue()`: next_retry_at <= now AND
 * attempt_count < max_attempts). This sweep closes that gap by fanning out
 * one CommunityDeploymentRetryJob per due row, each keyed by an idempotency
 * key derived from (deployment_uuid, attempt_count) so re-running the sweep
 * before a prior retry has advanced attempt_count is a no-op, not a
 * duplicate publish attempt.
 */
class CommunityDeploymentRetrySweepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $due = (new CommunityDeploymentModel())->listRetryDue();
        $svc = Services::jobService();

        $enqueued = 0;
        foreach ($due as $row) {
            $uuid = (string) $row['uuid'];
            // enqueueOnce, not enqueue: a retry job that finished without
            // advancing attempt_count (dead letter, invalidated approval) kept
            // its key, and enqueue() retires a finished job's key and queues a
            // fresh copy — so the same retry was re-run every five minutes.
            $jobId = $svc->enqueueOnce(
                'reach.community_deployment_retry',
                ['deployment_uuid' => $uuid],
                [
                    'queue'           => 'community',
                    'idempotency_key' => 'community_deploy_retry_' . $uuid . '_' . (int) ($row['attempt_count'] ?? 0),
                    'enqueued_actor_type' => 'system',
                    // The deployment row carries its own retry schedule (this
                    // sweep re-enqueues it when next due). A job-level retry
                    // only re-reached the same verdict: a dead-lettered
                    // deployment failed its job five times over. One attempt
                    // records the failure once.
                    'max_attempts'        => 1,
                ]
            );
            if ($jobId !== null) {
                $enqueued++;
            }
        }

        return ['ok' => true, 'due_count' => count($due), 'enqueued' => $enqueued];
    }
}
