<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Controllers\Api\V1\Intelligence\CompetitorController;
use App\Controllers\Api\V1\Intelligence\VisibilityController;
use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use App\Libraries\Intelligence\Visibility\AssistantAnswer;
use App\Libraries\Intelligence\Visibility\AssistantClient;
use App\Libraries\Intelligence\Visibility\AssistantException;
use App\Libraries\Intelligence\Visibility\AssistantRegistry;
use App\Libraries\Intelligence\Visibility\VisibilityRecordsService;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\ControllerTestTrait;
use Tests\Support\DatabaseTestCase;

/**
 * Every AI-visibility number on the overview opens a list of exactly the
 * answers it counted: the facet filters must agree with summary().
 *
 * @covers \App\Libraries\Intelligence\Visibility\VisibilityRecordsService
 * @covers \App\Controllers\Api\V1\Intelligence\VisibilityController
 * @covers \App\Controllers\Api\V1\Intelligence\CompetitorController
 */
final class VisibilityRecordsTest extends DatabaseTestCase
{
    use ControllerTestTrait;

    protected $migrateOnce = true;

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        $db->query('TRUNCATE reach_ai_visibility_citations, reach_ai_visibility_observations, reach_ai_visibility_responses, reach_ai_visibility_runs, reach_ai_visibility_batches RESTART IDENTITY CASCADE');
        $db->query("DELETE FROM reach_settings WHERE key LIKE 'visibility.%' OR key LIKE 'ai.%'");
        $db->query("UPDATE reach_ai_visibility_prompts SET status = 'active'");
        ReachConfig::flush();
        ReachConfig::set('visibility.daily_prompt_limit', 4);

        $gemini = static fn (string $q): AssistantAnswer => new AssistantAnswer('gemini', 'gemini-test', "Top picks:\n1. **Zoho Books** – cloud\n2. **Aicountly** – AI-first GST\n3. TallyPrime", [['url' => 'https://aicountly.com/billing', 'title' => 'aicountly.com']], 100);
        $openai = static fn (string $q): AssistantAnswer => new AssistantAnswer('openai', 'gpt-test', "- Tally: the default\n- Vyapar: billing for shops\nSee https://vyaparapp.in", [], 80);
        $broken = static function (string $q): AssistantAnswer {
            throw new AssistantException('HTTP 500 from provider');
        };
        $registry = new AssistantRegistry([
            new RecordsFakeAssistant('gemini', $gemini),
            new RecordsFakeAssistant('openai', $openai),
            new RecordsFakeAssistant('perplexity', $broken),
        ], ['gemini', 'openai', 'perplexity']);

        (new AiVisibilityService(null, $registry))->startBatch('manual', null, true);
    }

    protected function tearDown(): void
    {
        ReachConfig::flush();
        parent::tearDown();
    }

    public function testEveryOverviewCountMatchesTheListItOpens(): void
    {
        $s   = (new AiVisibilityService())->summary(7);
        $svc = new VisibilityRecordsService();
        $n   = static fn (array $f): int => $svc->runs($f)['total'];

        $this->assertSame(8, $s['overall']['answers']);
        $this->assertSame($s['overall']['answers'], $n(['days' => 7, 'status' => 'completed']));
        $this->assertSame($s['overall']['brand_mentions'], $n(['days' => 7, 'brand' => 'named']));
        $this->assertSame($s['overall']['brand_cited'], $n(['days' => 7, 'brand' => 'cited']));
        $this->assertSame(
            $s['overall']['brand_answers_counted'] - $s['overall']['brand_mentions'],
            $n(['days' => 7, 'brand' => 'not_named'])
        );

        foreach ($s['by_provider'] as $p) {
            $this->assertSame($p['answers'], $n(['days' => 7, 'status' => 'completed', 'provider' => $p['provider']]), $p['provider']);
            $this->assertSame($p['brand_mentions'], $n(['days' => 7, 'brand' => 'named', 'provider' => $p['provider']]));
        }
        $this->assertNotEmpty($s['overall']['entities']);
        foreach ($s['overall']['entities'] as $name => $e) {
            $this->assertSame($e['mentions'], $n(['days' => 7, 'entity' => $name]), (string) $name);
        }
        $this->assertNotEmpty($s['gaps']);
        foreach ($s['gaps'] as $g) {
            $this->assertSame($g['answers_without_us'], $n(['days' => 7, 'gap' => 1, 'prompt_id' => $g['prompt_id']]));
        }
        foreach ($s['by_market'] as $m) {
            $this->assertSame($m['answers'], $n(['days' => 7, 'status' => 'completed', 'market' => $m['market']]), $m['market']);
        }
        foreach ($s['top_cited_domains'] as $d) {
            $this->assertSame($d['answers'], $n(['days' => 7, 'cited_domain' => $d['domain']]), $d['domain']);
        }
        $this->assertSame(array_sum(array_column($s['failures'], 'failed_runs')), $n(['days' => 7, 'status' => 'failed']));
    }

    public function testRunListPagesAndRowsCarryWhatTheAnswerCountedFor(): void
    {
        $svc  = new VisibilityRecordsService();
        $page = $svc->runs(['provider' => 'gemini'], 1, 2);
        $this->assertSame(4, $page['total']);
        $this->assertCount(2, $page['rows']);
        $this->assertSame(['provider' => 'gemini'], $page['filters']);
        $row = $page['rows'][0];
        $this->assertTrue($row['brand_mentioned']);
        $this->assertSame(2, $row['brand_rank']);
        $this->assertContains('aicountly.com', $row['cited_domains']);

        $failed = $svc->runs(['status' => 'failed'])['rows'][0];
        $this->assertNull($failed['brand_mentioned']);
        $this->assertStringContainsString('HTTP 500', (string) $failed['error']);

        // Unknown values are dropped rather than trusted.
        $this->assertSame([], $svc->runs(['status' => 'bogus', 'brand' => 'x'])['filters']);
    }

    public function testRunDetailHasTheAnswerTextObservationsAndCitations(): void
    {
        $svc = new VisibilityRecordsService();
        $id  = $svc->runs(['provider' => 'gemini'])['rows'][0]['run_id'];
        $run = $svc->run($id);

        $this->assertNotNull($run);
        $this->assertStringContainsString('Zoho Books', $run['responses'][0]['text']);
        $this->assertContains('brand', array_column($run['observations'], 'mention_type'));
        $this->assertContains('Zoho Books', array_column($run['observations'], 'entity_mentioned'));
        $this->assertSame('aicountly.com', $run['citations'][0]['cited_domain']);
        $this->assertNull($svc->run(999999));
    }

    public function testCompetitorRateMatchesNamesInAnswersAndItsObservationList(): void
    {
        $db    = \Config\Database::connect();
        $tally = (int) $db->table('reach_competitors')->where('name', 'Tally')->get()->getRowArray()['id'];
        $s     = (new AiVisibilityService())->summary(7);
        $svc   = new VisibilityRecordsService();

        $rate = $svc->competitorRates(7, [$tally])[$tally];
        $this->assertSame($s['overall']['entities']['Tally']['mentions'], $rate['mentions']);
        $this->assertSame($s['overall']['entities']['Tally']['mention_rate'], $rate['mention_rate']);

        $obs = $svc->observations(['competitor_id' => $tally, 'coverage_state' => 'mentioned', 'counted' => 1, 'days' => 7]);
        $this->assertSame($rate['mentions'], $obs['total']);
        $this->assertSame('Tally', $obs['rows'][0]['entity_mentioned']);
        $this->assertFalse($obs['rows'][0]['in_question']);

        $this->assertSame($s['overall']['brand_mentions'], $svc->observations(['entity' => 'Aicountly', 'coverage_state' => 'mentioned', 'counted' => 1, 'days' => 7])['total']);
    }

    public function testControllersHonourTheUrlFilters(): void
    {
        $runs = json_decode((string) $this->withRequest($this->get(['provider' => 'openai', 'status' => 'completed', 'per_page' => 2]))
            ->controller(VisibilityController::class)->execute('runs')->getJSON(), true);
        $this->assertSame(4, $runs['meta']['total']);
        $this->assertCount(2, $runs['data']);
        $this->assertSame('openai', $runs['data'][0]['provider']);

        $detail = json_decode((string) $this->withRequest($this->get([]))
            ->controller(VisibilityController::class)->execute('run', $runs['data'][0]['run_id'])->getJSON(), true);
        $this->assertStringContainsString('Vyapar', $detail['data']['responses'][0]['text']);

        $obs = json_decode((string) $this->withRequest($this->get(['run_id' => $runs['data'][0]['run_id']]))
            ->controller(VisibilityController::class)->execute('observations')->getJSON(), true);
        $this->assertGreaterThan(0, $obs['meta']['total']);
        $this->assertSame([$runs['data'][0]['run_id']], array_values(array_unique(array_column($obs['data'], 'run_id'))));

        $db    = \Config\Database::connect();
        $tally = (int) $db->table('reach_competitors')->where('name', 'Tally')->get()->getRowArray()['id'];
        $list  = json_decode((string) $this->withRequest($this->get(['days' => 7]))
            ->controller(CompetitorController::class)->execute('index')->getJSON(), true)['data'];
        $row   = array_values(array_filter($list, static fn (array $c): bool => (int) $c['id'] === $tally))[0];
        $this->assertGreaterThan(0, $row['mention_rate']);

        $compObs = json_decode((string) $this->withRequest($this->get(['days' => 7]))
            ->controller(CompetitorController::class)->execute('observations', $tally)->getJSON(), true);
        $this->assertSame($row['mentions'], $compObs['meta']['total']);
    }

    public function testSeoOverviewRunCountMatchesTheRunListItOpens(): void
    {
        $overview = json_decode((string) $this->withRequest($this->get([]))
            ->controller(\App\Controllers\Api\V1\Intelligence\SeoCommandCentreController::class)->execute('overview')->getJSON(), true)['data'];
        $this->assertSame(12, (int) $overview['visibility_28d']['runs']);
        $this->assertSame((int) $overview['visibility_28d']['runs'], (new VisibilityRecordsService())->runs(['days' => 28])['total']);
    }

    /** @param array<string, mixed> $get */
    private function get(array $get): IncomingRequest
    {
        $config  = new \Config\App();
        $request = new IncomingRequest($config, new SiteURI($config), null, new UserAgent());
        $request->setGlobal('get', $get);

        return $request;
    }
}

/** A canned assistant for this test. */
final class RecordsFakeAssistant implements AssistantClient
{
    /** @var callable(string): AssistantAnswer */
    private $answer;

    public function __construct(private string $k, callable $answer)
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
        return null;
    }

    public function ask(string $question): AssistantAnswer
    {
        return ($this->answer)($question);
    }
}
