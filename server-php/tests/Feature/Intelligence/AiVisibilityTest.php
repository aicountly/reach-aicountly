<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use App\Libraries\Intelligence\Visibility\AssistantAnswer;
use App\Libraries\Intelligence\Visibility\AssistantClient;
use App\Libraries\Intelligence\Visibility\AssistantException;
use App\Libraries\Intelligence\Visibility\AssistantRegistry;
use App\Libraries\Intelligence\VisibilityExecutionService;
use App\Libraries\Settings\ReachConfig;
use Tests\Support\ApiTestCase;

/**
 * AI visibility end to end against the test database with fake assistants:
 * the seeded prompt set, a daily pass, parsing into observations/citations,
 * share of voice, cost cap, honest skips and the API.
 *
 * @covers \App\Libraries\Intelligence\Visibility\AiVisibilityService
 * @covers \App\Libraries\Intelligence\VisibilityExecutionService
 * @covers \App\Controllers\Api\V1\Intelligence\VisibilityController
 */
final class AiVisibilityTest extends ApiTestCase
{
    protected $migrateOnce = true;

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        $db->query('TRUNCATE reach_ai_visibility_citations, reach_ai_visibility_observations, reach_ai_visibility_responses, reach_ai_visibility_runs, reach_ai_visibility_batches RESTART IDENTITY CASCADE');
        $db->query("DELETE FROM reach_settings WHERE key LIKE 'visibility.%' OR key LIKE 'ai.%'");
        $db->query("UPDATE reach_ai_visibility_prompts SET status = 'active'");
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        ReachConfig::flush();
        parent::tearDown();
    }

    private function registry(?callable $gemini, ?callable $openai, ?callable $perplexity = null): AssistantRegistry
    {
        return new AssistantRegistry([
            new FakeAssistant('gemini', $gemini),
            new FakeAssistant('openai', $openai),
            new FakeAssistant('perplexity', $perplexity),
        ], ['gemini', 'openai', 'perplexity']);
    }

    private static function geminiAnswer(): callable
    {
        return static fn (string $q): AssistantAnswer => new AssistantAnswer('gemini', 'gemini-test', "Top picks:\n1. **Zoho Books** – cloud\n2. **AICOUNTLY** – AI-first GST\n3. TallyPrime", [['url' => 'https://aicountly.com/billing', 'title' => 'aicountly.com']], 100);
    }

    private static function openAiAnswer(): callable
    {
        return static fn (string $q): AssistantAnswer => new AssistantAnswer('openai', 'gpt-test', "- Tally: the default\n- Vyapar: billing for shops\nSee https://vyaparapp.in", [], 80);
    }

    public function testSeededPromptSetAndCompetitorsAreInPlace(): void
    {
        $db      = \Config\Database::connect();
        $prompts = $db->query("SELECT p.name, p.persona FROM reach_ai_visibility_prompts p JOIN reach_ai_visibility_prompt_versions v ON v.prompt_id = p.id AND v.is_active WHERE p.status = 'active'")->getResultArray();
        $this->assertGreaterThanOrEqual(20, count($prompts));
        foreach (['India', 'Singapore', 'UAE', 'Japan', 'Global'] as $market) {
            $this->assertContains($market, array_column($prompts, 'persona'));
        }
        $alias = $db->query("SELECT a.alias_value FROM reach_competitor_aliases a JOIN reach_competitors c ON c.id = a.competitor_id WHERE c.name = 'Tally'")->getResultArray();
        $this->assertContains('TallyPrime', array_column($alias, 'alias_value'));
    }

    public function testDailyPassRecordsMentionsRanksCompetitorsCitationsAndShareOfVoice(): void
    {
        ReachConfig::set('visibility.daily_prompt_limit', 3);
        $svc = new AiVisibilityService(null, $this->registry(self::geminiAnswer(), self::openAiAnswer()), static function (int $id): void {
            throw new \LogicException('inline pass must not enqueue');
        });

        $batch = $svc->startBatch('scheduled', null, true);

        $this->assertSame('completed', $batch['status']);
        $this->assertSame(3, $batch['prompts_planned']);
        $this->assertCount(6, $batch['run_ids']);
        $skipped = array_column($batch['providers'], 'will_run', 'key');
        $this->assertFalse($skipped['perplexity']);

        $db = \Config\Database::connect();
        $this->assertSame(6, (int) $db->table('reach_ai_visibility_runs')->where('status', 'completed')->countAllResults());
        $brandRows = $db->table('reach_ai_visibility_observations')->where('mention_type', 'brand')->get()->getResultArray();
        $this->assertCount(6, $brandRows);
        $this->assertSame(3, count(array_filter($brandRows, static fn ($r) => $r['coverage_state'] === 'mentioned' && (int) $r['mention_order'] === 2)));
        $this->assertGreaterThan(0, $db->table('reach_ai_visibility_citations')->where('cited_domain', 'aicountly.com')->countAllResults());

        $s     = $svc->summary(7);
        $byP   = array_column($s['by_provider'], null, 'provider');
        $this->assertSame(1.0, $byP['gemini']['brand_mention_rate']);
        $this->assertSame(2.0, $byP['gemini']['brand_avg_rank']);
        $this->assertSame(0.0, $byP['openai']['brand_mention_rate']);
        $this->assertSame(3, $byP['openai']['entities']['Tally']['mentions']);
        // gemini: AICOUNTLY, Zoho Books, Tally per answer → 1/3 share of voice each.
        $this->assertSame(0.3333, $byP['gemini']['entities']['AICOUNTLY']['share_of_voice']);
        $this->assertSame(3, $s['overall']['brand_cited']);
        $this->assertNotEmpty($s['gaps']);
        $this->assertContains('Tally', array_column($s['gaps'][0]['competitors'], 'name'));
        $this->assertSame(['openai'], $s['gaps'][0]['providers']);
        $this->assertContains('aicountly.com', array_column($s['top_cited_domains'], 'domain'));
        $this->assertSame(AiVisibilityService::istToday(), $s['series'][0]['date']);
        $this->assertNotEmpty($s['by_market']);

        // Same IST day: the scheduled pass does not run twice, and the cap stops a manual one.
        $again = $svc->startBatch('scheduled', null, true);
        $this->assertTrue($again['already_ran']);
        $manual = $svc->startBatch('manual', null, true);
        $this->assertSame('skipped', $manual['status']);
        $this->assertStringContainsString('cap', (string) $manual['note']);
        $this->assertSame(6, (int) $db->table('reach_ai_visibility_runs')->countAllResults());
    }

    public function testNoConfiguredAssistantIsSkippedHonestly(): void
    {
        $batch = (new AiVisibilityService(null, $this->registry(null, null, null)))->startBatch('scheduled', null, true);

        $this->assertSame('skipped', $batch['status']);
        $this->assertStringContainsString('No assistant is configured', (string) $batch['note']);
        $this->assertSame(0, (int) \Config\Database::connect()->table('reach_ai_visibility_runs')->countAllResults());
    }

    public function testProviderErrorFailsTheRunWithTheReason(): void
    {
        ReachConfig::set('visibility.daily_prompt_limit', 1);
        $svc = new AiVisibilityService(null, $this->registry(static function (string $q): AssistantAnswer {
            throw new AssistantException('Gemini returned HTTP 429: quota');
        }, null));

        $batch = $svc->startBatch('manual', null, true);
        $run   = \Config\Database::connect()->table('reach_ai_visibility_runs')->where('id', $batch['run_ids'][0])->get()->getRowArray();

        $this->assertSame('failed', $run['status']);
        $this->assertStringContainsString('HTTP 429', (string) $run['error_message']);
        $this->assertSame('completed', $batch['status']);
        $this->assertSame(1, $svc->summary(7)['failures'][0]['failed_runs']);
        $this->assertSame(0, $svc->summary(7)['overall']['answers']);
    }

    public function testQueuedRunsCompleteThroughTheExecutorAndCloseTheBatch(): void
    {
        ReachConfig::set('visibility.daily_prompt_limit', 2);
        $registry = $this->registry(self::geminiAnswer(), null);
        $queued   = [];
        $svc      = new AiVisibilityService(null, $registry, static function (int $id) use (&$queued): void {
            $queued[] = $id;
        });

        $batch = $svc->startBatch('scheduled');
        $this->assertSame('running', $batch['status']);
        $this->assertSame($batch['run_ids'], $queued);

        $exec = new VisibilityExecutionService($registry);
        foreach ($queued as $id) {
            $this->assertSame('completed', $exec->executeRun($id)['status']);
        }
        $this->assertTrue($exec->executeRun($queued[0])['skipped']);
        $this->assertSame('completed', $svc->batches(1)[0]['status']);
    }

    public function testApiEndpoints(): void
    {
        ReachConfig::set('visibility.daily_prompt_limit', 1);
        (new AiVisibilityService(null, $this->registry(self::geminiAnswer(), null)))->startBatch('manual', null, true);

        $h = $this->authAs('super_admin');

        $summary = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/visibility/summary', ['days' => 7])->getJSON(), true)['data'];
        $this->assertSame(1, $summary['overall']['answers']);
        $this->assertSame('gemini', $summary['by_provider'][0]['provider']);

        $answers = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/visibility/answers')->getJSON(), true)['data'];
        $this->assertTrue($answers[0]['brand_mentioned']);
        $this->assertSame(2, $answers[0]['brand_rank']);
        $this->assertStringContainsString('Zoho Books', (string) $answers[0]['answer_text']);

        // No real keys in the test DB: every assistant is reported as skipped, and run-now refuses.
        $providers = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/visibility/providers')->getJSON(), true)['data'];
        $this->assertSame([false, false, false], array_column($providers, 'will_run'));
        $this->withHeaders($h)->call('POST', 'v1/intelligence/visibility/batches')->assertStatus(409);

        $prompts = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/visibility/prompts', ['status' => 'all'])->getJSON(), true)['data'];
        $this->assertNotEmpty($prompts[0]['active_version']['prompt_text']);
        $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/intelligence/visibility/runs', ['prompt_version_id' => $prompts[0]['active_version']['id']])->assertStatus(409);

        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/intelligence/visibility/prompts/' . $prompts[0]['id'], ['status' => 'paused'])->assertStatus(200);
        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/intelligence/visibility/prompts/' . $prompts[0]['id'], ['status' => 'bogus'])->assertStatus(422);

        $this->withHeaders($this->authAs('viewer'))->call('GET', 'v1/intelligence/visibility/summary')->assertStatus(403);
    }
}

/** A canned assistant: null answer function = not configured. */
final class FakeAssistant implements AssistantClient
{
    /** @var (callable(string): AssistantAnswer)|null */
    private $answer;

    public function __construct(private string $k, ?callable $answer)
    {
        $this->answer = $answer;
    }

    public function key(): string
    {
        return $this->k;
    }

    public function label(): string
    {
        return ucfirst($this->k);
    }

    public function model(): string
    {
        return $this->k . '-test';
    }

    public function unavailableReason(): ?string
    {
        return $this->answer === null ? 'No ' . $this->k . ' key (test).' : null;
    }

    public function ask(string $question): AssistantAnswer
    {
        if ($this->answer === null) {
            throw new AssistantException('not configured');
        }

        return ($this->answer)($question);
    }
}
