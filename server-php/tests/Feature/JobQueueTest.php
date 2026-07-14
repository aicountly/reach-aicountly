<?php

namespace Tests\Feature;

use App\Libraries\JobService;
use App\Libraries\JobHandlerRegistry;
use App\Jobs\HealthCheckJob;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * B2 tests #7–#9 — Job enqueue, worker success, retry + dead-letter.
 * All exercised against the reach_jobs table via JobService.
 */
final class JobQueueTest extends DatabaseTestCase
{
    private function service(): JobService
    {
        // Create a new JobService instance directly so it shares the same
        // DB connection as the test's transaction wrapper ($this->db).
        // Services::reset() must NOT be called here: it would create a new
        // connection outside the current test transaction, causing enqueued
        // jobs to persist across tests and break reserve() ordering.
        return new JobService();
    }

    public function testEnqueueCreatesPendingRow(): void
    {
        $svc = $this->service();
        $jobId = $svc->enqueue('reach.health_check', ['payload' => 1]);
        $this->assertGreaterThan(0, $jobId);

        $row = \Config\Database::connect()
            ->table('reach_jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertSame('reach.health_check', $row['job_type']);
    }

    public function testWorkerReservesAndCompletesJob(): void
    {
        $svc = $this->service();
        $svc->enqueue('reach.health_check', ['ok' => true]);

        // Register the health-check handler so the worker path completes.
        /** @var JobHandlerRegistry $registry */
        $registry = Services::jobHandlers();
        $registry->register('reach.health_check', new HealthCheckJob());

        $reserved = $svc->reserve('default', 'test-worker-1', 30);
        $this->assertNotNull($reserved);
        $this->assertSame('processing', $reserved['status']);

        $result = $registry->execute($reserved);
        $svc->markCompleted((int) $reserved['id'], $result);

        $row = \Config\Database::connect()
            ->table('reach_jobs')->where('id', $reserved['id'])->get()->getRowArray();
        $this->assertSame('completed', $row['status']);
        $this->assertNotNull($row['completed_at']);
    }

    public function testRetriesMoveExhaustedJobsToDeadLetter(): void
    {
        $svc   = $this->service();
        // max_attempts=1 means the very first markFailed() exhausts all attempts
        // and transitions the job directly to dead_letter — no backoff bypass needed.
        $jobId = $svc->enqueue('reach.health_check', ['fail' => true], [
            'max_attempts' => 1,
        ]);

        $r = $svc->reserve('default', 'test-worker-fail', 30);
        $this->assertNotNull($r, 'Job should be reservable');
        $svc->markFailed((int) $r['id'], 'simulated error');

        $row = \Config\Database::connect()
            ->table('reach_jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('dead_letter', $row['status']);
    }
}
