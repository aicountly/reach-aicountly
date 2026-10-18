<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Commands\ReachSchedule;
use App\Libraries\Bot\BotActivityRegistry;
use App\Libraries\Bot\CronSchedule;
use App\Libraries\Intelligence\GrowthIntelSchedule;
use App\Libraries\JobHandlerRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The activity registry is what the owner sees and what "Run now" enqueues.
 * It must say exactly what the scheduler does: the same job types, queues,
 * payload shape and times. These tests walk a whole week minute by minute.
 *
 * @internal
 */
final class BotActivityRegistryTest extends CIUnitTestCase
{
    /** @return array<string, list<array<string, mixed>>> minute (Y-m-d H:i) => planned jobs */
    private static function plannedWeek(): array
    {
        static $week = null;
        if ($week !== null) {
            return $week;
        }
        $week  = [];
        $start = new \DateTimeImmutable('2026-10-18 00:00:00', new \DateTimeZone('UTC')); // a Sunday
        for ($i = 0; $i < 7 * 1440; $i++) {
            $at   = $start->modify("+{$i} minutes");
            $jobs = ReachSchedule::plannedJobs($at);
            foreach (GrowthIntelSchedule::plannedJobs($at, true, true) as $g) {
                $jobs[] = ['type' => $g['type'], 'payload' => $g['payload'], 'opts' => ['queue' => 'default', 'idempotency_key' => $g['key']]];
            }
            $week[$at->format('Y-m-d H:i')] = $jobs;
        }
        return $week;
    }

    /** @return array<string, array<string, mixed>> */
    private static function jobEntries(): array
    {
        return array_filter(BotActivityRegistry::all(), static fn ($e) => $e['kind'] === 'job');
    }

    public function testEveryScheduledJobTypeIsListedWithItsQueue(): void
    {
        $byType = [];
        foreach (self::jobEntries() as $key => $e) {
            $byType[$e['job_type']] = $e + ['key' => $key];
        }
        foreach (self::plannedWeek() as $minute => $jobs) {
            foreach ($jobs as $job) {
                $this->assertArrayHasKey($job['type'], $byType, "{$job['type']} (planned at {$minute}) is missing from BotActivityRegistry");
                $this->assertSame($job['opts']['queue'] ?? 'default', $byType[$job['type']]['queue'], "{$job['type']} queue");
            }
        }
    }

    public function testRegistryCronMatchesWhenTheSchedulerFirstEnqueuesEachPeriod(): void
    {
        $utc     = new \DateTimeZone('UTC');
        $seen    = [];
        $entries = [];
        foreach (self::jobEntries() as $key => $e) {
            $entries[$e['job_type']] = ['key' => $key, 'cron' => new CronSchedule($e['cron'])] + $e;
        }

        $i = 0;
        foreach (self::plannedWeek() as $minute => $jobs) {
            $at      = new \DateTimeImmutable($minute . ':00', $utc);
            $planned = [];
            $warmUp  = $i++ < 60; // a period already running when the walk starts is first seen mid-period
            foreach ($jobs as $job) {
                $planned[$job['type']] = $job;
                $k = $job['opts']['idempotency_key'];
                if (! isset($seen[$k])) {
                    $seen[$k] = true;
                    if ($warmUp) {
                        continue;
                    }
                    $this->assertTrue($entries[$job['type']]['cron']->matches($at), "{$job['type']} first enqueued for key {$k} at {$minute} UTC, but its registry cron is '{$entries[$job['type']]['cron']->expression}'");
                }
            }
            foreach ($entries as $type => $e) {
                if ($e['cron']->matches($at)) {
                    $this->assertArrayHasKey($type, $planned, "Registry says {$e['key']} runs at {$minute} UTC ('{$e['cron']->expression}'), but the scheduler plans nothing then");
                    // Run now builds the same payload shape the scheduler would.
                    $manual = ($e['payload'])($at, 7);
                    foreach (array_keys($planned[$type]['payload']) as $field) {
                        $this->assertArrayHasKey($field, $manual, "{$e['key']} manual payload lacks '{$field}'");
                        if ($field !== 'trigger') {
                            $this->assertSame($planned[$type]['payload'][$field], $manual[$field], "{$e['key']} payload field '{$field}' at {$minute}");
                        }
                    }
                }
            }
        }
    }

    public function testEveryJobTypeHasAHandler(): void
    {
        $handlers = new JobHandlerRegistry();
        foreach (self::jobEntries() as $key => $e) {
            $this->assertTrue($handlers->has($e['job_type']), "{$key}: no handler for {$e['job_type']}");
        }
        $this->assertTrue($handlers->has(BotActivityRegistry::COMMAND_JOB_TYPE));
    }

    public function testEveryCommandActivityNamesARealSparkCommand(): void
    {
        $names = [];
        foreach (glob(APPPATH . 'Commands/*.php') as $file) {
            if (preg_match("/protected \\\$name\\s*=\\s*'([^']+)'/", (string) file_get_contents($file), $m)) {
                $names[] = $m[1];
            }
        }
        foreach (BotActivityRegistry::all() as $key => $e) {
            if ($e['kind'] !== 'command') {
                continue;
            }
            $this->assertContains($e['command'], $names, "{$key}: php spark {$e['command']} does not exist");
            $this->assertSame(['command' => $e['command'], 'args' => array_values($e['args'])], BotActivityRegistry::commandFor($key));
        }
        $this->assertNull(BotActivityRegistry::commandFor('seo_tracker'), 'only command activities can start a spark command');
        $this->assertNull(BotActivityRegistry::commandFor('nope'));
    }

    public function testEveryRoutineHasItsPromptFileAndEveryEntryIsComplete(): void
    {
        $repoRoot = dirname(ROOTPATH);
        $this->assertFileExists($repoRoot . '/docs/automation/routines/reach-command.md');
        foreach (BotActivityRegistry::all() as $key => $e) {
            foreach (['name', 'group', 'kind', 'description', 'cron', 'schedule'] as $field) {
                $this->assertNotEmpty($e[$field] ?? null, "{$key}.{$field}");
            }
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{3,60}$/', $key);
            new CronSchedule($e['cron']); // parses
            if ($e['kind'] === 'routine') {
                $this->assertFileExists($repoRoot . '/' . $e['prompt_file'], $key);
            }
            if ($e['kind'] === 'worker') {
                $this->assertNotEmpty($e['not_runnable'], "{$key} must say why it cannot be run");
            }
            if (isset($e['schedule']) && str_contains($e['schedule'], 'IST')) {
                $this->assertStringContainsString('UTC', $e['schedule'], "{$key}: give both IST and UTC");
            }
        }
    }

    public function testPaidActivitiesCarryACostNote(): void
    {
        $all = BotActivityRegistry::all();
        foreach (['seo_tracker', 'ai_visibility_daily', 'ai_insights_weekly', 'daily_analytics_report'] as $key) {
            $this->assertNotEmpty($all[$key]['cost'] ?? null, $key);
        }
        $this->assertStringContainsString('Paid DataForSEO SERP calls', $all['seo_tracker']['cost']);
    }
}
