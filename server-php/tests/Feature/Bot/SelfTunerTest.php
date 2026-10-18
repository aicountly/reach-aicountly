<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Libraries\Bot\Plan\ActualsSnapshotService;
use App\Libraries\Bot\Plan\LagSignals;
use App\Libraries\Bot\Plan\PerformanceService;
use App\Libraries\Bot\Plan\PlanInputs;
use App\Libraries\Bot\Plan\PlanPeriods;
use App\Libraries\Bot\Plan\SelfTuner;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * The learning loop: lagging targets with a structural cause are lowered (and
 * restored), chronic lags are lowered only within bounds, stuck work is
 * retried, every action is logged — and nothing is applied with the switch off.
 */
final class SelfTunerTest extends DatabaseTestCase
{
    use BotPlanFixtures;

    private const FROM = '2026-10-11';
    private const TO   = '2026-10-17';

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        ReachConfig::flush();
        parent::tearDown();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} week perf + signals */
    private function review(array $integrations = []): array
    {
        $db      = Database::connect();
        $signals = new LagSignals($db, self::integrations($integrations));
        $week    = (new PerformanceService($db, new ActualsSnapshotService($db), $signals))->compute(self::FROM, self::TO);

        return [$week, $signals->gather(self::FROM, self::TO)];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-18 01:00:00', new \DateTimeZone('Asia/Kolkata'));
    }

    private function zeros(): array
    {
        return array_fill_keys(PlanPeriods::dates(self::FROM, self::TO), 0.0);
    }

    public function testMissingIntegrationLowersTheTargetToZeroAndRestoresItOnceConfigured(): void
    {
        $this->seedDayPlans(self::FROM, self::TO);
        $this->seedActuals('video.vlog.published', $this->zeros());

        [$week, $sig] = $this->review(['shotstack' => false]);
        $vlog = array_column($week['metrics'], null, 'metric_key')['video.vlog.published'];
        $this->assertSame('lagging', $vlog['status']);
        $this->assertSame('integration_missing', $vlog['causes'][0]['code']);

        $res = (new SelfTuner(Database::connect()))->run(self::TO, $week, $sig, null, null, $this->now());
        $this->assertTrue($res['enabled']);
        $lowered = array_values(array_filter($res['actions'], static fn ($a) => $a['action'] === 'lower_target' && $a['metric_key'] === 'video.vlog.published'));
        $this->assertCount(1, $lowered);
        $this->assertTrue($lowered[0]['applied']);
        $this->assertSame(0.0, $lowered[0]['after']['target_per_day']);
        $this->assertSame(1.0, $lowered[0]['before']['target_per_day']);
        $this->assertStringContainsString('Shotstack', $lowered[0]['reason']);
        $this->assertSame(0.0, PlanInputs::overrides()['video.vlog.published']['target_value']);

        $log = SelfTuner::recentLog();
        $this->assertNotEmpty(array_filter($log, static fn ($l) => $l['action'] === 'lower_target' && $l['applied']));

        // Shotstack is configured now → the override goes away on the next run.
        [$week2, $sig2] = $this->review();
        $res2 = (new SelfTuner(Database::connect()))->run('2026-10-18', $week2, $sig2, null, null, $this->now()->modify('+1 day'));
        $restored = array_values(array_filter($res2['actions'], static fn ($a) => $a['action'] === 'restore_target' && $a['metric_key'] === 'video.vlog.published'));
        $this->assertCount(1, $restored);
        $this->assertTrue($restored[0]['applied']);
        $this->assertArrayNotHasKey('video.vlog.published', PlanInputs::overrides());
    }

    public function testChronicLagIsLoweredWithinBoundsAndOnlyOncePerCooldown(): void
    {
        $this->seedDayPlans(self::FROM, self::TO);
        // 1 of 6 blogs a day for 7 days, routine is submitting (so no structural cause).
        $this->seedActuals('content.blog.published', array_fill_keys(PlanPeriods::dates(self::FROM, self::TO), 1.0));

        [$week, $sig] = $this->review();
        $blog = array_column($week['metrics'], null, 'metric_key')['content.blog.published'];
        $this->assertSame(['type' => 'lag', 'days' => 7], $blog['streak']);

        $res = (new SelfTuner(Database::connect()))->run(self::TO, $week, $sig, null, null, $this->now());
        $act = array_values(array_filter($res['actions'], static fn ($a) => $a['metric_key'] === 'content.blog.published' && $a['action'] === 'lower_target'))[0];
        $this->assertSame(3.0, $act['after']['target_per_day'], 'Achieved 1/day, but never below 50 % of the base 6.');
        $this->assertStringContainsString('never below 50%', $act['reason']);

        // Same evidence the next day: cooldown → no second lowering.
        $res2 = (new SelfTuner(Database::connect()))->run('2026-10-18', $week, $sig, null, null, $this->now()->modify('+1 day'));
        $this->assertSame([], array_values(array_filter($res2['actions'], static fn ($a) => $a['metric_key'] === 'content.blog.published' && $a['action'] === 'lower_target')));
    }

    public function testOffSwitchLogsWhatItWouldDoButAppliesNothing(): void
    {
        ReachConfig::set('bot.self_tune.enabled', false);
        ReachConfig::flush();
        $this->seedDayPlans(self::FROM, self::TO);
        $this->seedActuals('video.vlog.published', $this->zeros());
        $db = Database::connect();
        $db->table('reach_jobs')->insert(['job_type' => 'reach.search_console_ingest', 'status' => 'dead_letter', 'error_message' => 'HTTP 500', 'updated_at' => gmdate('Y-m-d H:i:sP')]);
        $jobId = (int) $db->insertID();

        [$week, $sig] = $this->review(['shotstack' => false]);
        $res = (new SelfTuner($db))->run(self::TO, $week, $sig, null, null, new \DateTimeImmutable('now'));

        $this->assertFalse($res['enabled']);
        $this->assertSame(0, $res['applied']);
        $this->assertGreaterThan(0, $res['skipped']);
        foreach ($res['actions'] as $a) {
            $this->assertFalse($a['applied']);
            $this->assertSame('self_tune_disabled', $a['skipped_reason']);
        }
        $this->assertSame([], PlanInputs::overrides(), 'No override written.');
        $this->assertSame('dead_letter', $db->table('reach_jobs')->where('id', $jobId)->get()->getRowArray()['status'], 'Job untouched.');
        $this->assertSame(count($res['actions']), $db->table('reach_bot_self_tune_log')->where('applied', false)->countAllResults());
    }

    public function testStuckDraftsAndSafeDeadLettersAreRetriedWithinBounds(): void
    {
        $db  = Database::connect();
        $old = gmdate('Y-m-d H:i:sP', time() - 7200);
        $far = gmdate('Y-m-d H:i:sP', time() + 5 * 3600);
        for ($i = 0; $i < 12; $i++) {
            $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => "Stuck {$i}", 'slug' => "stuck-{$i}"]);
            $db->table('reach_routine_submissions')->insert(['content_type' => 'blog', 'content_item_id' => (int) $db->insertID(), 'status' => 'pending_vetting',
                'vetting_attempts' => 5, 'next_attempt_at' => $far, 'created_at' => $old, 'updated_at' => $old]);
        }
        $db->table('reach_jobs')->insert(['job_type' => 'reach.search_console_ingest', 'status' => 'dead_letter', 'updated_at' => gmdate('Y-m-d H:i:sP')]);
        $safe = (int) $db->insertID();
        $db->table('reach_jobs')->insert(['job_type' => 'reach.publication', 'status' => 'dead_letter', 'updated_at' => gmdate('Y-m-d H:i:sP')]);
        $unsafe = (int) $db->insertID();

        $retried = [];
        $tuner = new SelfTuner($db, static function (int $id) use (&$retried): void {
            $retried[] = $id;
        });
        $sig = ['integrations' => ['gemini' => true, 'openai' => true]];
        $res = $tuner->run('2026-10-17', ['metrics' => []], $sig, null, null, new \DateTimeImmutable('now'));

        $requeued = array_filter($res['actions'], static fn ($a) => $a['action'] === 'requeue_stuck');
        $this->assertCount(SelfTuner::MAX_REQUEUE, $requeued, 'Bounded per run.');
        $this->assertSame(SelfTuner::MAX_REQUEUE, $db->table('reach_routine_submissions')->where('next_attempt_at <=', gmdate('Y-m-d H:i:sP', time() + 60))->countAllResults());
        $this->assertSame([$safe], $retried, 'Only allow-listed idempotent job types are retried.');
        $this->assertNotContains($unsafe, $retried);

        // Same day again: each submission at most once a day, each job once ever.
        $retried = [];
        $res2 = $tuner->run('2026-10-17', ['metrics' => []], $sig, null, null, new \DateTimeImmutable('now'));
        $second = array_values(array_filter($res2['actions'], static fn ($a) => $a['action'] === 'requeue_stuck'));
        $this->assertCount(2, $second, 'Only the two the first run had no room for.');
        $this->assertSame([], array_intersect(array_column($second, 'subject_id'), array_column($requeued, 'subject_id')));
        $this->assertSame([], $retried);
        $res3 = $tuner->run('2026-10-17', ['metrics' => []], $sig, null, null, new \DateTimeImmutable('now'));
        $this->assertCount(0, array_filter($res3['actions'], static fn ($a) => $a['action'] === 'requeue_stuck'));
    }

    public function testNoProviderNoRequeue(): void
    {
        $db  = Database::connect();
        $old = gmdate('Y-m-d H:i:sP', time() - 7200);
        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Stuck', 'slug' => 'stuck']);
        $db->table('reach_routine_submissions')->insert(['content_type' => 'blog', 'content_item_id' => (int) $db->insertID(), 'status' => 'pending_vetting',
            'next_attempt_at' => gmdate('Y-m-d H:i:sP', time() + 5 * 3600), 'created_at' => $old, 'updated_at' => $old]);

        $res = (new SelfTuner($db))->run('2026-10-17', ['metrics' => []], ['integrations' => ['gemini' => false]], null, null, new \DateTimeImmutable('now'));
        $this->assertSame([], $res['actions'], 'Retrying vetting without a Gemini key would only fail again.');
    }
}
