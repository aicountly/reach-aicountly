<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\GrowthIntelSchedule;
use App\Libraries\Intelligence\Insights\InsightsDigestBuilder;
use App\Libraries\Intelligence\Insights\NumberAudit;
use App\Libraries\Intelligence\Insights\SiteSignalsProbe;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Intelligence\Insights\NumberAudit
 * @covers \App\Libraries\Intelligence\Insights\SiteSignalsProbe
 * @covers \App\Libraries\Intelligence\Insights\InsightsDigestBuilder::readinessChecks
 * @covers \App\Libraries\Intelligence\GrowthIntelSchedule::plannedJobs
 */
final class InsightsHelpersTest extends CIUnitTestCase
{
    public function testNumberAuditFlagsOnlyFiguresNotInTheDigest(): void
    {
        $digest = ['search_console' => ['totals' => ['clicks' => 1234, 'impressions' => 56789, 'ctr' => 0.0217]], 'ai_visibility' => ['brand_mention_rate' => 0.125]];
        $report = [
            'summary'         => 'Clicks reached 1,234 from 56,789 impressions (CTR 2.17%). AICOUNTLY appears in 12.5% of answers.',
            'gaps'            => [['finding' => 'Traffic grew 340% and we have 9 comparison pages', 'evidence' => 'An estimated 5,000 visitors']],
            'recommendations' => [['title' => 'Ship 3 comparison pages within 14 days', 'horizon_days' => 14, 'detail' => 'In 2026, aim for 10-15 days turnaround on 1500 words']],
            'assumptions'     => ['The Indian SMB market has 63 million businesses.'],
        ];

        $this->assertSame(['340%', '5,000'], NumberAudit::unverified($digest, $report));
    }

    public function testRobotsParsingFindsSiteWideAiCrawlerBlocks(): void
    {
        $robots = "User-agent: *\nDisallow: /admin\n\nUser-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n\nUser-agent: PerplexityBot\nAllow: /\n";
        $this->assertSame(['GPTBot', 'CCBot'], SiteSignalsProbe::blockedCrawlers($robots));
        $this->assertSame([], SiteSignalsProbe::blockedCrawlers("User-agent: *\nAllow: /\n"));
        $this->assertContains('ClaudeBot', SiteSignalsProbe::blockedCrawlers("User-agent: *\nDisallow: /\n"));
    }

    /** @return callable(string): array{status: int, body: string} */
    private function site(array $pages): callable
    {
        return static function (string $url) use ($pages): array {
            foreach ($pages as $suffix => $resp) {
                if (str_ends_with($url, $suffix) || str_contains($url, $suffix)) {
                    return $resp;
                }
            }
            return ['status' => 404, 'body' => 'not found'];
        };
    }

    public function testProbeReadsLlmsTxtSchemaSitemapAndWikidata(): void
    {
        $home = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"AICOUNTLY","url":"https://aicountly.com","logo":"x.png","sameAs":["https://www.linkedin.com/company/aicountly","https://www.g2.com/products/aicountly"]},{"@type":"WebSite"}]}</script>'
            . '<script type="application/ld+json">{"@type":"SoftwareApplication","name":"Smart Books"}</script></head></html>';
        $sitemap = '<urlset><url><loc>https://aicountly.com/blog/a</loc></url><url><loc>https://aicountly.com/compare/aicountly-vs-tally</loc></url><url><loc>https://aicountly.com/help/getting-started</loc></url><url><loc>https://aicountly.com/pricing</loc></url></urlset>';
        $probe = new SiteSignalsProbe($this->site([
            '/llms.txt'    => ['status' => 200, 'body' => "# AICOUNTLY\n> AI accounting for Indian SMBs"],
            '/robots.txt'  => ['status' => 200, 'body' => "User-agent: *\nAllow: /\n"],
            '/sitemap.xml' => ['status' => 200, 'body' => $sitemap],
            'wikidata.org' => ['status' => 200, 'body' => json_encode(['search' => [['id' => 'Q1', 'label' => 'Aicountly', 'description' => 'x']]])],
            'aicountly.com/' => ['status' => 200, 'body' => $home],
        ]));

        $s = $probe->probe('https://aicountly.com/');

        $this->assertTrue($s['llms_txt']['present']);
        $this->assertContains('Organization', $s['schema']['types']);
        $this->assertContains('SoftwareApplication', $s['schema']['types']);
        $this->assertSame(['www.linkedin.com', 'www.g2.com'], $s['schema']['organization']['same_as_hosts']);
        $this->assertSame([], $s['robots']['blocked_ai_crawlers']);
        $this->assertSame(4, $s['sitemap']['urls']);
        $this->assertSame(1, $s['sitemap']['comparison_pages']);
        $this->assertSame(1, $s['sitemap']['help_docs_pages']);
        $this->assertTrue($s['sitemap']['pricing_page']);
        $this->assertSame('Q1', $s['wikidata']['entities'][0]['id']);

        $checks = array_column(InsightsDigestBuilder::readinessChecks(['brand' => 'AICOUNTLY', 'site_signals' => $s, 'content' => ['published_total_by_type' => ['knowledge_base' => 12]]]), 'status', 'key');
        $this->assertSame('done', $checks['llms_txt']);
        $this->assertSame('done', $checks['schema_organization']);
        $this->assertSame('partial', $checks['schema_software_application']);
        $this->assertSame('partial', $checks['entity_consistency']);
        $this->assertSame('partial', $checks['review_sites']);
        $this->assertSame('done', $checks['wikidata']);
        $this->assertSame('done', $checks['ai_crawlers_allowed']);
        $this->assertSame('partial', $checks['comparison_pages']);
        $this->assertSame('partial', $checks['docs_depth']);
    }

    public function testUnreachableSiteIsUnknownNotMissing(): void
    {
        $probe  = new SiteSignalsProbe(static fn (string $u): array => ['status' => 0, 'body' => '']);
        $checks = array_column(InsightsDigestBuilder::readinessChecks(['site_signals' => $probe->probe('https://aicountly.com')]), 'status', 'key');

        $this->assertSame('unknown', $checks['llms_txt']);
        $this->assertSame('unknown', $checks['schema_organization']);
        $this->assertSame('unknown', $checks['wikidata']);
        $this->assertSame('unknown', $checks['ai_crawlers_allowed']);
        $this->assertSame('unknown', $checks['comparison_pages']);
        $this->assertNotContains('missing', $checks);
    }

    public function testMissingLlmsTxtAndSchemaAreReportedMissing(): void
    {
        $probe  = new SiteSignalsProbe($this->site(['aicountly.com/' => ['status' => 200, 'body' => '<html>no json-ld</html>'], '/llms.txt' => ['status' => 404, 'body' => '']]));
        $checks = array_column(InsightsDigestBuilder::readinessChecks(['site_signals' => $probe->probe('https://aicountly.com')]), 'status', 'key');
        $this->assertSame('missing', $checks['llms_txt']);
        $this->assertSame('missing', $checks['schema_organization']);
        $this->assertSame('missing', $checks['entity_consistency']);
    }

    public function testScheduleRunsVisibilityAt0500IstAndInsightsOnSundayAfternoon(): void
    {
        $utc = new \DateTimeZone('UTC');
        // 2026-10-17 23:40 UTC = 2026-10-18 05:10 IST (a Sunday)
        $jobs = GrowthIntelSchedule::plannedJobs(new \DateTimeImmutable('2026-10-17 23:40:00', $utc), true, true);
        $this->assertSame([['type' => 'reach.ai_visibility_daily', 'payload' => ['trigger' => 'scheduled', 'date' => '2026-10-18'], 'key' => 'ai-visibility-daily-2026-10-18']], $jobs);

        // 2026-10-18 10:45 UTC = 16:15 IST Sunday
        $jobs = GrowthIntelSchedule::plannedJobs(new \DateTimeImmutable('2026-10-18 10:45:00', $utc), true, true);
        $this->assertSame(['reach.ai_insights_run'], array_column($jobs, 'type'));
        $this->assertSame('ai-insights-weekly-2026-10-18', $jobs[0]['key']);

        // Saturday 16:15 IST: nothing; switches off: nothing.
        $this->assertSame([], GrowthIntelSchedule::plannedJobs(new \DateTimeImmutable('2026-10-17 10:45:00', $utc), true, true));
        $this->assertSame([], GrowthIntelSchedule::plannedJobs(new \DateTimeImmutable('2026-10-17 23:40:00', $utc), false, false));
    }
}
