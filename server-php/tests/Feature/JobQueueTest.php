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
        // Soft-reset so each test starts with a fresh JobService without
        // re-initializing the autoloader (which reset(true) would do).
        Services::reset(false);
        return Services::jobService();
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
        $jobId = $svc->enqueue('reach.health_check', ['fail' => true], [
            'max_attempts' => 2,
        ]);

        for ($i = 0; $i < 2; $i++) {
            // JobService::markFailed() applies exponential backoff (base 15 s) so
            // the job won't be immediately re-reservable after the first failure.
            // Reset available_at to NOW() so the next iteration can reserve it
            // without waiting for the backoff window to expire.
            if ($i > 0) {
                \Config\Database::connect()
                    ->table('reach_jobs')
                    ->where('id', $jobId)
                    ->where('status', 'pending')
                    ->update(['available_at' => date('Y-m-d H:i:s')]);
            }

            $r = $svc->reserve('default', 'test-worker-fail', 30);
            $this->assertNotNull($r, "Should be reservable on attempt {$i}");
            $svc->markFailed((int) $r['id'], 'simulated error');
        }

        $row = \Config\Database::connect()
            ->table('reach_jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('dead_letter', $row['status']);
    }
}
