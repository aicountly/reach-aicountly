<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Jobs\BotActivityCommandJob;
use App\Libraries\Bot\BotActivityRegistry;
use App\Libraries\Bot\SocialChannelReadiness;
use App\Libraries\Bot\SparkCommandRunner;
use App\Libraries\JobContext;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Bot → Activities: GET v1/bot/activities and POST v1/bot/activities/{key}/run.
 *
 * @covers \App\Libraries\Bot\BotActivityService
 * @covers \App\Controllers\Api\V1\Bot\BotActivityController
 *
 * @internal
 */
final class BotActivitiesApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SocialChannelReadiness::override(static fn (): array => []);
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        SocialChannelReadiness::override(null);
        BotActivityCommandJob::useRunner(null);
        ReachConfig::flush();
        parent::tearDown();
    }

    private function runNow(string $key, array $headers, ?string $idem = null)
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->call('POST', "v1/bot/activities/{$key}/run", $idem !== null ? ['idempotency_key' => $idem] : []);
    }

    private static function body($res): array
    {
        return json_decode((string) $res->getJSON(), true);
    }

    public function testListsEveryActivityWithScheduleNextRunAndSafety(): void
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/bot/activities');
        $res->assertStatus(200);
        $rows = array_column(self::body($res)['data']['activities'], null, 'key');

        $this->assertCount(count(BotActivityRegistry::all()), $rows);
        $tracker = $rows['seo_tracker'];
        $this->assertSame('30 0 * * *', $tracker['cron_utc']);
        $this->assertStringContainsString('06:00 IST', $tracker['schedule']);
        $this->assertStringContainsString('UTC', $tracker['schedule']);
        $this->assertMatchesRegularExpression('/ 06:00 IST$/', $tracker['next_run_ist']);
        $this->assertStringContainsString('Paid DataForSEO', $tracker['cost_note']);
        $this->assertTrue($tracker['safe_to_run_now']);
        $this->assertSame('reach.seo_tracker', $tracker['job_type']);

        $this->assertFalse($rows['worker_main']['safe_to_run_now']);
        $this->assertNotEmpty($rows['worker_main']['not_runnable_reason']);
        // Routines need the reach-command trigger; without it they say so.
        $this->assertFalse($rows['routine_reach_blog']['safe_to_run_now']);
        $this->assertStringContainsString('not configured', $rows['routine_reach_blog']['not_runnable_reason']);
        $this->assertSame('php spark reach:blog-dispatch --force', $rows['blog_dispatch']['command']);
    }

    public function testPermissionIsRequired(): void
    {
        $headers = $this->authAs('analyst');
        $this->withHeaders($headers)->call('GET', 'v1/bot/activities')->assertStatus(403);
        $this->runNow('seo_tracker', $headers, 'idem-analyst-0001')->assertStatus(403);
        $this->assertSame(0, Database::connect()->table('reach_jobs')->where('job_type', 'reach.seo_tracker')->countAllResults());
    }

    public function testDedicatedBotRunCapabilityIsEnough(): void
    {
        Database::connect()->table('reach_roles')->insert(['slug' => 'bot_operator', 'name' => 'Bot operator', 'description' => 't', 'permissions' => json_encode(['bot.run'])]);
        $headers = $this->authAs('bot_operator');
        $this->withHeaders($headers)->call('GET', 'v1/bot/activities')->assertStatus(200);
        $this->runNow('content_base_sync', $headers, 'idem-operator-0001')->assertStatus(202);
    }

    public function testRunNowEnqueuesTheSchedulersOwnJobOnceAndAudits(): void
    {
        $headers = $this->authAs('super_admin');
        $db      = Database::connect();

        $res = $this->runNow('seo_tracker', $headers, 'idem-seo-tracker-0001');
        $res->assertStatus(202);
        $data = self::body($res)['data'];
        $this->assertFalse($data['replayed']);
        $jobId = $data['run']['job']['id'];

        $job = $db->table('reach_jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('reach.seo_tracker', $job['job_type'], 'same job type as the scheduler');
        $this->assertSame('default', $job['queue']);
        $this->assertSame('pending', $job['status']);
        $this->assertSame('human', $job['enqueued_actor_type']);
        $payload = json_decode((string) $job['payload_json'], true);
        $this->assertSame((new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'), $payload['date']);
        $owner = (int) $db->table('reach_users')->where('email', 'super_admin@test.aicountly.org')->get()->getRowArray()['id'];
        $this->assertSame($owner, (int) $job['enqueued_by_user_id']);

        // Same idempotency key: the first run comes back, nothing new is queued.
        $again = $this->runNow('seo_tracker', $headers, 'idem-seo-tracker-0001');
        $again->assertStatus(200);
        $this->assertTrue(self::body($again)['data']['replayed']);
        $this->assertSame($jobId, self::body($again)['data']['run']['job']['id']);
        $this->assertSame(1, $db->table('reach_jobs')->where('job_type', 'reach.seo_tracker')->countAllResults());

        // A new click while the first is still queued is refused.
        $busy = $this->runNow('seo_tracker', $headers, 'idem-seo-tracker-0002');
        $busy->assertStatus(409);
        $this->assertSame('busy', self::body($busy)['details']['reason']);

        // Finished, but inside the cooldown: 429 with Retry-After.
        $db->table('reach_jobs')->where('id', $jobId)->update(['status' => 'completed', 'completed_at' => gmdate('Y-m-d H:i:s')]);
        $cool = $this->runNow('seo_tracker', $headers, 'idem-seo-tracker-0003');
        $cool->assertStatus(429);
        $this->assertNotSame('', $cool->response()->getHeaderLine('Retry-After'));

        $audit = $db->table('reach_audit_logs')->where('action', 'bot.activity.run')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame($owner, (int) $audit[0]['user_id']);
        $this->assertSame('human', $audit[0]['actor_type']);
        $this->assertStringContainsString('reach.seo_tracker', (string) $audit[0]['new_value']);

        // The listing now shows the manual run as the last run, linked to its job.
        $list = array_column(self::body($this->withHeaders($headers)->call('GET', 'v1/bot/activities'))['data']['activities'], null, 'key');
        $this->assertSame($jobId, $list['seo_tracker']['last_run']['job_id']);
        $this->assertSame('manual', $list['seo_tracker']['last_run']['source']);
        $this->assertSame('/admin/jobs/' . $jobId, $list['seo_tracker']['last_run']['link']);
    }

    public function testGrowthIntelRunsAreManualSingleAttemptJobs(): void
    {
        $res = $this->runNow('ai_visibility_daily', $this->authAs('super_admin'), 'idem-visibility-0001');
        $res->assertStatus(202);
        $job = Database::connect()->table('reach_jobs')->where('id', self::body($res)['data']['run']['job']['id'])->get()->getRowArray();
        $this->assertSame('reach.ai_visibility_daily', $job['job_type']);
        $this->assertSame(1, (int) $job['max_attempts']);
        $this->assertSame('manual', json_decode((string) $job['payload_json'], true)['trigger']);
    }

    public function testCommandActivityQueuesTheCommandJobThatRunsTheCronCommand(): void
    {
        $res = $this->runNow('blog_dispatch', $this->authAs('super_admin'), 'idem-blog-dispatch-0001');
        $res->assertStatus(202);
        $jobId = self::body($res)['data']['run']['job']['id'];
        $job   = Database::connect()->table('reach_jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame(BotActivityRegistry::COMMAND_JOB_TYPE, $job['job_type']);
        $this->assertSame(['activity_key' => 'blog_dispatch'], json_decode((string) $job['payload_json'], true));
        $this->assertSame(1, (int) $job['max_attempts']);

        $runner = new class () extends SparkCommandRunner {
            public array $calls = [];
            public int $exit = 0;

            public function run(string $command, array $args = [], int $timeoutSecs = self::DEFAULT_TIMEOUT_SECS): array
            {
                $this->calls[] = [$command, $args];
                return ['exit_code' => $this->exit, 'output' => '{"event":"blog_dispatch"}', 'timed_out' => false, 'duration_ms' => 12];
            }
        };
        BotActivityCommandJob::useRunner($runner);
        $ctx    = JobContext::fromJobRow($job, 'test');
        $result = (new BotActivityCommandJob())->handle(['activity_key' => 'blog_dispatch'], $ctx);
        $this->assertSame([['reach:blog-dispatch', ['--force']]], $runner->calls);
        $this->assertSame('php spark reach:blog-dispatch --force', $result['command']);

        // A failing command fails the job instead of reporting success.
        $runner->exit = 2;
        try {
            (new BotActivityCommandJob())->handle(['activity_key' => 'blog_dispatch'], $ctx);
            $this->fail('non-zero exit must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exited with code 2', $e->getMessage());
        }
        // Only registry commands: a payload can never name another one.
        $this->expectException(\RuntimeException::class);
        (new BotActivityCommandJob())->handle(['activity_key' => 'seo_tracker'], $ctx);
    }

    public function testRefusals(): void
    {
        $headers = $this->authAs('super_admin');
        $this->runNow('worker_main', $headers, 'idem-worker-00001')->assertStatus(409);
        $this->runNow('no_such_activity', $headers, 'idem-nothing-0001')->assertStatus(404);
        $routine = $this->runNow('routine_reach_blog', $headers, 'idem-routine-0001');
        $routine->assertStatus(409);
        $this->assertSame('not_configured', self::body($routine)['details']['reason']);
        $this->assertNotEmpty(self::body($routine)['details']['setup']);
        $this->assertSame(0, Database::connect()->table('reach_bot_commands')->countAllResults(), 'nothing is stored or fired when not configured');
    }
}
