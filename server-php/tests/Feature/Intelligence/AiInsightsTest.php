<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Libraries\Intelligence\Insights\InsightsDigestBuilder;
use App\Libraries\Intelligence\Insights\InsightsService;
use App\Libraries\Intelligence\Insights\InsightsUnavailableException;
use App\Libraries\Intelligence\Insights\SiteSignalsProbe;
use App\Libraries\MarketingBotService;
use App\Libraries\Settings\ReachConfig;
use Tests\Support\ApiTestCase;

/**
 * AI Insights against the test database with a fake Gemini transport and an
 * unreachable site: stored reports and actions, the number audit, observed
 * checklist facts winning over the model, honest failures, the API, the
 * automation endpoints for the strategy routine, and the Marketing Bot
 * actions that now read the real report.
 *
 * @covers \App\Libraries\Intelligence\Insights\InsightsService
 * @covers \App\Libraries\Intelligence\Insights\InsightsDigestBuilder
 * @covers \App\Libraries\Intelligence\Insights\StrategyMemoService
 * @covers \App\Libraries\Intelligence\Insights\GrowthIntelligenceSummary
 * @covers \App\Controllers\Api\V1\Intelligence\InsightsController
 * @covers \App\Controllers\Api\V1\Intelligence\InsightsAutomationController
 */
final class AiInsightsTest extends ApiTestCase
{
    protected $migrateOnce = true;

    private const TOKEN = 'test-automation-token-0123456789-abcdef';

    /** @var list<array> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        $db->query('TRUNCATE reach_strategy_memos, reach_ai_insight_actions, reach_ai_insight_reports RESTART IDENTITY CASCADE');
        $db->query('TRUNCATE reach_ai_visibility_citations, reach_ai_visibility_observations, reach_ai_visibility_responses, reach_ai_visibility_runs, reach_ai_visibility_batches RESTART IDENTITY CASCADE');
        $db->query("DELETE FROM reach_settings WHERE key LIKE 'ai.%'");
        $db->query("DELETE FROM reach_jobs WHERE job_type = 'reach.ai_insights_run'");
        ReachConfig::flush();
        foreach (['putenv', '_ENV', '_SERVER'] as $target) {
            if ($target === 'putenv') {
                putenv('REACH_AUTOMATION_TOKEN=' . self::TOKEN);
            } else {
                $GLOBALS[$target]['REACH_AUTOMATION_TOKEN'] = self::TOKEN;
            }
        }
    }

    protected function tearDown(): void
    {
        // Bot approvals would block the older migrations' down() when the next class regresses.
        \Config\Database::connect()->query("DELETE FROM reach_approvals WHERE subject_type = 'bot'");
        putenv('REACH_AUTOMATION_TOKEN');
        unset($_ENV['REACH_AUTOMATION_TOKEN'], $_SERVER['REACH_AUTOMATION_TOKEN']);
        ReachConfig::flush();
        parent::tearDown();
    }

    private function builder(): InsightsDigestBuilder
    {
        return new InsightsDigestBuilder(
            null,
            new SiteSignalsProbe(static fn (string $url): array => ['status' => 0, 'body' => '']),
            static fn (): array => ['available' => false, 'reason' => 'GA4 is not configured (test).'],
        );
    }

    private function modelReport(): array
    {
        return [
            'summary'       => 'Little data yet: 4,321 visitors came from AI search.',
            'whats_working' => [['finding' => 'Vetting is running', 'evidence' => 'vetting section']],
            'gaps'          => [['area' => 'AI visibility', 'finding' => 'No answers stored yet', 'evidence' => 'ai_visibility.available = false']],
            'recommendations' => [
                ['title' => 'Publish llms.txt', 'detail' => 'Add /llms.txt listing products.', 'expected_impact' => 'high', 'impact_rationale' => 'Assistants read it.', 'effort' => 'S', 'owner_type' => 'engineering', 'horizon_days' => 3],
                ['title' => 'Write AICOUNTLY vs Tally page', 'detail' => 'Comparison page.', 'expected_impact' => 'medium', 'impact_rationale' => 'Gap prompts.', 'effort' => 'M', 'owner_type' => 'bogus-owner', 'horizon_days' => 99],
            ],
            'ai_readiness_checklist' => [
                ['key' => 'llms_txt', 'item' => 'llms.txt', 'status' => 'done', 'evidence' => 'model guess', 'next_step' => 'Publish /llms.txt'],
                ['key' => 'g2_reviews_count', 'item' => '20 G2 reviews', 'status' => 'done', 'evidence' => 'guess', 'next_step' => 'Ask customers'],
            ],
            'internationalisation' => ['markets_to_test' => [['market' => 'UAE', 'rationale' => 'Assumption: VAT SMEs', 'first_step' => 'English landing page']], 'localisation_needs' => ['VAT invoices']],
            'investor_readiness' => ['metrics_investors_ask' => [['metric' => 'MRR', 'have_data' => false, 'evidence_or_gap' => 'Not tracked in Reach']], 'narrative_points' => ['AI-first'], 'data_room_gaps' => ['Cohort retention']],
            'assumptions'   => ['The UAE has many SMEs.'],
        ];
    }

    private function gemini(int $status = 200, ?array $report = null): callable
    {
        return function (string $url, array $body) use ($status, $report): array {
            $this->requests[] = ['url' => $url, 'body' => $body];
            if ($status !== 200) {
                return ['status' => $status, 'body' => json_encode(['error' => ['message' => 'backend error key=abc']])];
            }
            return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($report ?? $this->modelReport())]]]]]])];
        };
    }

    private function runReport(): array
    {
        ReachConfig::set('ai.gemini.api_key', 'g-test-key');
        ReachConfig::set('ai.insights.model', 'gemini-insights-test');

        return (new InsightsService(null, $this->builder(), $this->gemini()))->run('manual');
    }

    public function testRunStoresReportActionsAuditsNumbersAndKeepsObservedChecklistFacts(): void
    {
        $r = $this->runReport();

        $this->assertSame('completed', $r['status']);
        $this->assertSame('gemini-insights-test', $r['model']);
        $this->assertStringContainsString('/models/gemini-insights-test:generateContent', $this->requests[0]['url']);
        $this->assertStringContainsString('Use ONLY numbers', $this->requests[0]['body']['systemInstruction']['parts'][0]['text']);
        $this->assertStringContainsString('"search_console"', $this->requests[0]['body']['contents'][0]['parts'][0]['text']);

        // Neither "4,321" (summary) nor "20" (a checklist item the model added) is in the digest.
        $this->assertSame(['4,321', '20'], $r['unverified_numbers']);
        $checks = array_column($r['report']['ai_readiness_checklist'], null, 'key');
        $this->assertSame('unknown', $checks['llms_txt']['status'], 'site unreachable: the model cannot mark it done');
        $this->assertSame('observed', $checks['llms_txt']['source']);
        $this->assertSame('Publish /llms.txt', $checks['llms_txt']['next_step']);
        $this->assertSame('unknown', $checks['g2_reviews_count']['status']);
        $this->assertSame('model', $checks['g2_reviews_count']['source']);

        $this->assertCount(2, $r['actions']);
        $this->assertSame(['open', 'open'], array_column($r['actions'], 'status'));
        $this->assertSame('marketing', $r['actions'][1]['owner_type']);
        $this->assertSame(30, $r['actions'][1]['horizon_days']);

        $this->assertFalse($r['digest']['ga4']['available']);
        $this->assertArrayHasKey('ai_readiness_checks', $r['digest']);
    }

    public function testNotConfiguredStoresAFailedRunAndMakesNoCall(): void
    {
        try {
            (new InsightsService(null, $this->builder(), $this->gemini()))->run('scheduled');
            $this->fail('expected InsightsUnavailableException');
        } catch (InsightsUnavailableException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }
        $this->assertSame([], $this->requests);
        $row = \Config\Database::connect()->table('reach_ai_insight_reports')->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertNull((new InsightsService())->latest());
    }

    public function testProviderErrorIsRetriedOnceThenStoredAsFailed(): void
    {
        ReachConfig::set('ai.gemini.api_key', 'g-test-key');
        try {
            (new InsightsService(null, $this->builder(), $this->gemini(500)))->run('manual');
            $this->fail('expected InsightsUnavailableException');
        } catch (InsightsUnavailableException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringNotContainsString('key=abc', $e->getMessage());
        }
        $this->assertCount(2, $this->requests);
        $this->assertSame('failed', \Config\Database::connect()->table('reach_ai_insight_reports')->get()->getRowArray()['status']);
    }

    public function testApiLatestHistoryActionsAndRun(): void
    {
        $h = $this->authAs('super_admin');
        $this->withHeaders($h)->call('POST', 'v1/intelligence/insights/run')->assertStatus(409);

        $report = $this->runReport();
        $latest = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/insights/latest')->getJSON(), true)['data'];
        $this->assertSame($report['id'], $latest['report']['id']);
        $this->assertTrue($latest['configured']);

        $history = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/insights')->getJSON(), true)['data'];
        $this->assertSame(2, $history[0]['recommendation_count']);

        $actionId = $report['actions'][0]['id'];
        $done     = $this->withHeaders($h)->withBodyFormat('json')->call('PATCH', 'v1/intelligence/insights/actions/' . $actionId, ['status' => 'done', 'note' => 'shipped']);
        $done->assertStatus(200);
        $this->assertSame('done', json_decode((string) $done->getJSON(), true)['data']['status']);
        $this->withHeaders($h)->withBodyFormat('json')->call('PATCH', 'v1/intelligence/insights/actions/' . $actionId, ['status' => 'finished'])->assertStatus(422);
        $open = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/insights/actions', ['status' => 'open'])->getJSON(), true)['data'];
        $this->assertCount(1, $open);

        $queued = $this->withHeaders($h)->call('POST', 'v1/intelligence/insights/run');
        $queued->assertStatus(202);
        $this->assertSame(1, \Config\Database::connect()->table('reach_jobs')->where('job_type', 'reach.ai_insights_run')->countAllResults());

        $this->withHeaders($this->authAs('viewer'))->call('GET', 'v1/intelligence/insights/latest')->assertStatus(403);
    }

    public function testAutomationDigestAndStrategyMemos(): void
    {
        $this->runReport();

        $this->call('GET', 'v1/automation/insights-digest')->assertStatus(401);
        $auto = ['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'];
        $digest = $this->withHeaders($auto)->call('GET', 'v1/automation/insights-digest');
        $digest->assertStatus(200);
        $d = json_decode((string) $digest->getJSON(), true)['data'];
        $this->assertSame('Publish llms.txt', $d['insights_report']['report']['recommendations'][0]['title']);
        $this->assertArrayHasKey('ai_visibility_28d', $d);
        $this->assertCount(2, $d['open_actions']);

        $this->withHeaders($auto)->withBodyFormat('json')->call('POST', 'v1/automation/strategy-memos', ['title' => 'x', 'body_markdown' => 'too short'])->assertStatus(422);

        $memo = ['title' => 'Growth strategy — week 42', 'body_markdown' => "# Growth strategy\n\n" . str_repeat('Concrete actions grounded in the digest. ', 10), 'routine_run_id' => 'session_abc', 'model' => 'claude-sonnet', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18', 'sources' => ['v1/automation/insights-digest']];
        $first = $this->withHeaders($auto)->withBodyFormat('json')->call('POST', 'v1/automation/strategy-memos', $memo);
        $first->assertStatus(201);
        $id = json_decode((string) $first->getJSON(), true)['data']['id'];
        $dup = $this->withHeaders($auto)->withBodyFormat('json')->call('POST', 'v1/automation/strategy-memos', $memo);
        $dup->assertStatus(200);
        $this->assertTrue(json_decode((string) $dup->getJSON(), true)['data']['duplicate']);

        $h     = $this->authAs('super_admin');
        $memos = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/insights/memos')->getJSON(), true)['data'];
        $this->assertSame('Growth strategy — week 42', $memos[0]['title']);
        $one = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/intelligence/insights/memos/' . $id)->getJSON(), true)['data'];
        $this->assertStringStartsWith('# Growth strategy', $one['body_markdown']);
        $this->assertSame(['v1/automation/insights-digest'], $one['sources']);
    }

    public function testMarketingBotAnalyticsActionsUseTheRealReportOrFailHonestly(): void
    {
        $userId = $this->ensureUser('super_admin', $this->ensureRole('super_admin'), []);

        $failed = (new MarketingBotService())->execute('generate_analytics_summary', [], $userId);
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('not configured', (string) $failed['result']['error']);

        $report = $this->runReport();
        $res    = (new MarketingBotService())->execute('recommend_campaign_improvements', [], $userId);
        $this->assertNotSame('failed', $res['status']);
        $this->assertSame(['Publish llms.txt', 'Write AICOUNTLY vs Tally page'], array_column($res['result']['content_generated']['recommendations'], 'title'));
        $this->assertSame($report['id'], $res['result']['evidence']['insights_report_id']);

        $sum = (new MarketingBotService())->execute('generate_analytics_summary', [], $userId);
        $this->assertSame('Little data yet: 4,321 visitors came from AI search.', $sum['result']['content_generated']['summary']);
        $this->assertContains('4,321', $sum['result']['content_generated']['unverified_numbers']);
    }

    public function testDailyReportSummaryBlock(): void
    {
        $this->runReport();
        $block = (new \App\Libraries\Intelligence\Insights\GrowthIntelligenceSummary())->forDailyReport();

        $this->assertTrue($block['available']);
        $this->assertSame(2, $block['open_actions']);
        $this->assertSame(0, $block['ai_visibility_7d']['answers']);
        $this->assertSame(2, $block['insights']['unverified_numbers']);
    }
}
