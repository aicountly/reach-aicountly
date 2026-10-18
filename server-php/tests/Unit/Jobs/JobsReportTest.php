<?php

namespace Tests\Unit\Jobs;

use App\Commands\ReachJobsReport;
use App\Libraries\JobRunner;
use App\Models\Intelligence\IngestionCursorModel;
use CodeIgniter\Test\CIUnitTestCase;

final class JobsReportTest extends CIUnitTestCase
{
    public function testReportListsFailingTypesWithTheirLastErrorAndUndrainedQueues(): void
    {
        $lines = implode("\n", ReachJobsReport::render([
            'available' => true, 'window_hours' => 24,
            'completed' => 812, 'failed' => 288, 'skipped' => 7, 'queued' => 34, 'oldest_queued_secs' => 3 * 86400,
            'scheduled' => 2, 'retrying' => 1, 'running' => 0, 'stalled' => 1,
            'failing_types' => [[
                'job_type' => 'reach.content_overdue_escalation', 'failed' => 288, 'retrying' => 1,
                'last_at' => '2026-10-18 01:04:00+00', 'last_error' => "pg_query(): Query failed:\nERROR: column ci.created_by does not exist",
            ]],
            'queues' => [
                ['queue' => 'default', 'queued' => 1, 'oldest_queued_secs' => 30, 'running' => 0, 'completed' => 800, 'failed' => 0, 'undrained' => false],
                ['queue' => 'marketing_bot', 'queued' => 33, 'oldest_queued_secs' => 3 * 86400, 'running' => 0, 'completed' => 0, 'failed' => 0, 'undrained' => true],
            ],
        ]));

        $this->assertStringContainsString('completed 812 · failed 288 · skipped 7', $lines);
        $this->assertStringContainsString('queued 34 (oldest 3d)', $lines);
        $this->assertStringContainsString('reach.content_overdue_escalation', $lines);
        $this->assertStringContainsString('last error: pg_query(): Query failed: ERROR: column ci.created_by does not exist', $lines, 'one line, pasteable');
        $this->assertMatchesRegularExpression('/marketing_bot .*no worker seems to drain this queue/', $lines);
        $this->assertStringContainsString('expired lease', $lines);
    }

    public function testQuietQueueSaysSo(): void
    {
        $lines = ReachJobsReport::render([
            'available' => true, 'window_hours' => 6, 'completed' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => 0,
            'oldest_queued_secs' => null, 'scheduled' => 0, 'retrying' => 0, 'running' => 0, 'stalled' => 0,
            'failing_types' => [], 'queues' => [],
        ]);
        $this->assertContains('No job type failed or is retrying in the last 6 h.', $lines);
    }

    public function testSkipReasonRecognisesNotConfiguredResultsOnly(): void
    {
        $this->assertSame('Set the key', JobRunner::skipReason(['status' => 'not_configured', 'detail' => 'Set the key']));
        $this->assertSame('SEARCH_CONSOLE_ENABLED is false.', JobRunner::skipReason(['status' => 'skipped', 'reason' => 'SEARCH_CONSOLE_ENABLED is false.']));
        $this->assertSame('disabled', JobRunner::skipReason(['status' => 'disabled']));
        $this->assertNull(JobRunner::skipReason(['status' => 'ok']));
        $this->assertNull(JobRunner::skipReason(['sent' => false, 'reason' => 'no_deficit']), 'nothing to do is a completion');
        $this->assertNull(JobRunner::skipReason(['backfill' => ['status' => 'skipped']]), 'only the top-level status counts');
    }

    public function testPostgresBooleansAreReadAsBooleans(): void
    {
        $this->assertFalse(IngestionCursorModel::pgBool('f'));
        $this->assertTrue(IngestionCursorModel::pgBool('t'));
        $this->assertFalse(IngestionCursorModel::pgBool(false));
        $this->assertFalse(IngestionCursorModel::pgBool(null));
        $this->assertTrue(IngestionCursorModel::pgBool(true));
    }
}
