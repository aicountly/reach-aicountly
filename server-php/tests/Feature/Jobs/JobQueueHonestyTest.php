<?php

namespace Tests\Feature\Jobs;

use App\Commands\ReachSchedule;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\JobHandlerRegistry;
use App\Libraries\JobRunner;
use App\Libraries\JobService;
use App\Libraries\JobSkippedException;
use App\Libraries\NonRetryableJobException;
use Tests\Support\DatabaseTestCase;

/**
 * The queue must tell "did not run by design" (skipped) from "failed", stop
 * retrying what a retry cannot fix, never re-run a job that keeps killing its
 * worker, never re-queue a scheduled job inside its period, and prune.
 */
final class JobQueueHonestyTest extends DatabaseTestCase
{
    private function row(int $id): array
    {
        return \Config\Database::connect()->table('reach_jobs')->where('id', $id)->get()->getRowArray();
    }

    private function runOne(JobService $svc, JobRunner $runner, string $queue = 'default'): array
    {
        $reserved = $svc->reserve($queue, 'test-worker', 30);
        $this->assertNotNull($reserved, 'a job should be reservable');

        return $runner->run($reserved, 'test-worker') + ['id' => (int) $reserved['id']];
    }

    private static function handler(callable $fn): JobHandlerInterface
    {
        return new class ($fn) implements JobHandlerInterface {
            public function __construct(private $fn)
            {
            }

            public function handle(array $payload, JobContext $ctx): array
            {
                return ($this->fn)($payload);
            }
        };
    }

    public function testOutcomesSkippedDeadLetterAndRetry(): void
    {
        $svc      = new JobService();
        $registry = new JobHandlerRegistry();
        $runner   = new JobRunner($svc, $registry);

        $registry->register('t.throws_skip', self::handler(static fn () => throw new JobSkippedException('Gemini key not set')));
        $registry->register('t.result_skip', self::handler(static fn () => ['status' => 'not_configured', 'detail' => 'Set the DataForSEO login']));
        $registry->register('t.bad_payload', self::handler(static fn () => throw new \InvalidArgumentException('work_block_id required')));
        $registry->register('t.terminal', self::handler(static fn () => throw new NonRetryableJobException('deployment dead-lettered')));
        $registry->register('t.flaky', self::handler(static fn () => throw new \RuntimeException('HTTP 503')));
        $registry->register('t.ok', self::handler(static fn () => ['status' => 'ok']));

        // 1. Skip via exception: terminal, reason recorded, not a failure.
        $id = $svc->enqueue('t.throws_skip');
        $this->assertSame('skipped', $this->runOne($svc, $runner)['outcome']);
        $row = $this->row($id);
        $this->assertSame('skipped', $row['status']);
        $this->assertSame('Gemini key not set', $row['error_message']);
        $this->assertNotNull($row['completed_at']);

        // 2. Skip via the handler's own result status.
        $id = $svc->enqueue('t.result_skip');
        $this->runOne($svc, $runner);
        $this->assertSame('skipped', $this->row($id)['status']);
        $this->assertSame('Set the DataForSEO login', $this->row($id)['error_message']);

        // 3. Bad payload and terminal failures dead-letter on attempt 1 of 5.
        foreach (['t.bad_payload', 't.terminal'] as $type) {
            $id = $svc->enqueue($type);
            $this->runOne($svc, $runner);
            $row = $this->row($id);
            $this->assertSame('dead_letter', $row['status'], $type);
            $this->assertSame(1, (int) $row['attempts'], $type);
        }

        // 4. No handler registered: dead letter at once, saying so.
        $id = $svc->enqueue('t.nobody_handles_this');
        $this->runOne($svc, $runner);
        $this->assertSame('dead_letter', $this->row($id)['status']);
        $this->assertStringContainsString('No handler registered', $this->row($id)['error_message']);

        // 5. Transient failure: back to pending with backoff, error kept.
        $id = $svc->enqueue('t.flaky');
        $this->assertSame('failed', $this->runOne($svc, $runner)['outcome']);
        $row = $this->row($id);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('HTTP 503', $row['error_message']);

        // 6. Success.
        $id = $svc->enqueue('t.ok');
        $this->runOne($svc, $runner);
        $this->assertSame('completed', $this->row($id)['status']);
    }

    public function testExpiredLeaseDeadLettersExhaustedJobsInsteadOfLoopingForEver(): void
    {
        $svc = new JobService();
        $db  = \Config\Database::connect();

        $exhausted = $svc->enqueue('t.kills_worker', [], ['max_attempts' => 3]);
        $fresh     = $svc->enqueue('t.kills_worker_once', [], ['max_attempts' => 3]);
        $expired   = date('Y-m-d H:i:s', time() - 600);
        $db->table('reach_jobs')->where('id', $exhausted)->update(['status' => 'processing', 'attempts' => 3, 'lease_expires_at' => $expired]);
        $db->table('reach_jobs')->where('id', $fresh)->update(['status' => 'processing', 'attempts' => 1, 'lease_expires_at' => $expired]);

        $this->assertSame(2, $svc->recoverExpiredLeases());

        $this->assertSame('dead_letter', $this->row($exhausted)['status']);
        $this->assertStringContainsString('lease expired', $this->row($exhausted)['error_message']);
        $this->assertSame('pending', $this->row($fresh)['status']);
    }

    public function testPruneKeepsFailuresLongerAndAgesRowsWithoutCompletedAt(): void
    {
        $svc = new JobService();
        $db  = \Config\Database::connect();
        $at  = static fn (int $days): string => date('Y-m-d H:i:s', time() - $days * 86400);

        $ids = [];
        foreach ([
            'completed_8d'    => ['completed', 8, true],
            'completed_2d'    => ['completed', 2, true],
            'skipped_8d'      => ['skipped', 8, true],
            'dead_10d'        => ['dead_letter', 10, true],
            'dead_20d'        => ['dead_letter', 20, true],
            'nocompletedat_9d' => ['cancelled', 9, false],
            'pending_30d'     => ['pending', 30, false],
        ] as $name => [$status, $days, $withCompletedAt]) {
            $db->table('reach_jobs')->insert([
                'job_type' => 't.prune', 'status' => $status,
                'completed_at' => $withCompletedAt ? $at($days) : null,
                'created_at' => $at($days), 'updated_at' => $at($days),
            ]);
            $ids[$name] = (int) $db->insertID();
        }

        $this->assertSame(4, $svc->pruneOlderThanDays(7, 14));

        $left = array_map('intval', array_column($db->table('reach_jobs')->select('id')->where('job_type', 't.prune')->get()->getResultArray(), 'id'));
        sort($left);
        $this->assertSame([$ids['completed_2d'], $ids['dead_10d'], $ids['pending_30d']], $left);
    }

    public function testScheduledJobsAreEnqueuedOncePerPeriodAsSystem(): void
    {
        $svc = new JobService();
        $now = new \DateTimeImmutable('2026-10-18 00:56:00', new \DateTimeZone('UTC'));

        // Two ticks a minute apart in the same hour, and one where the first
        // job already finished (dead-lettered) — the production pattern that
        // queued reach.content_overdue_escalation every couple of minutes.
        foreach ([$now, $now->modify('+1 minute'), $now->modify('+2 minutes')] as $i => $tick) {
            foreach (ReachSchedule::plannedJobs($tick) as $job) {
                $svc->enqueueOnce($job['type'], $job['payload'], $job['opts']);
            }
            if ($i === 0) {
                \Config\Database::connect()->table('reach_jobs')
                    ->where('job_type', 'reach.content_overdue_escalation')
                    ->update(['status' => 'dead_letter', 'completed_at' => date('Y-m-d H:i:s')]);
            }
        }

        $rows = \Config\Database::connect()->table('reach_jobs')
            ->where('job_type', 'reach.content_overdue_escalation')->get()->getResultArray();
        $this->assertCount(1, $rows, 'one per hour, even after it finished');
        $this->assertSame('system', $rows[0]['enqueued_actor_type'], 'the scheduler is not a human');

        // A plain enqueue with no user id is the system's too.
        $id = $svc->enqueue('t.any');
        $this->assertSame('system', $this->row($id)['enqueued_actor_type']);
        $id = $svc->enqueue('t.any', [], ['enqueued_by_user_id' => 7]);
        $this->assertSame('human', $this->row($id)['enqueued_actor_type']);
    }
}
