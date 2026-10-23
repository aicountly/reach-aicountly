<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Libraries\Reports\DailyReportBuilder;
use App\Libraries\Reports\DailyReportRenderer;
use CodeIgniter\Test\CIUnitTestCase;

final class DailyReportRendererTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private static function summary(array $sections): array
    {
        $sections += [
            'search'      => ['configured' => false, 'note' => 'Not configured — Search Console is not connected.'],
            'traffic'     => ['configured' => false, 'note' => 'Not configured — set GA4_PROPERTY_ID_MARKETING.'],
            'seo_tracker' => ['configured' => false, 'status' => 'not_configured', 'note' => 'Not configured — set DataForSEO.'],
            'content'     => ['configured' => true, 'counts' => ['blog' => 2, 'community' => 0, 'knowledge_base' => 1, 'release_note' => null, 'workflow_guide' => null], 'total' => 3],
            'vetting'     => ['configured' => false, 'note' => 'Not configured — AI vetting runs are not recorded.'],
            'pipeline'    => ['configured' => true, 'failed_jobs_24h' => 1, 'dead_letters' => 2, 'stalled_jobs' => 0, 'pending_over_1h' => 0],
        ];
        $sections['todo'] = ['configured' => true, 'items' => DailyReportBuilder::todo($sections)];

        return ['report_date' => '2026-10-17', 'generated_at' => '2026-10-17 08:00:00+05:30', 'timezone' => 'Asia/Kolkata', 'sections' => $sections];
    }

    public function testUnconfiguredSourcesSayNotConfiguredAndShowNoFigures(): void
    {
        $s    = self::summary([]);
        $html = DailyReportRenderer::html($s);
        $text = DailyReportRenderer::text($s);

        $this->assertStringContainsString('Not configured — Search Console is not connected.', $html);
        $this->assertStringContainsString('Not configured — set GA4_PROPERTY_ID_MARKETING.', $text);
        $this->assertStringContainsString('Not tracked yet', $html, 'A content type with no source is not reported as 0.');
        $this->assertStringContainsString('Release notes: not tracked yet', $text);
        $this->assertStringNotContainsString('<style', $html, 'Mail clients strip <style>; everything is inline.');
        $this->assertSame('Aicountly daily report — Sat 17 Oct 2026', DailyReportRenderer::subject($s));
    }

    public function testGeneratedTimeIsShownInIstWithItsLabel(): void
    {
        $s = self::summary([]);
        $s['generated_at'] = '2026-10-17 02:30:00+00:00'; // stored/serialised in UTC
        $this->assertStringContainsString('Generated 17-10-2026 08:00 IST', DailyReportRenderer::html($s));
        $this->assertStringContainsString("Generated 17-10-2026 08:00 IST\n", DailyReportRenderer::text($s));
        $this->assertStringNotContainsString('+00:00', DailyReportRenderer::text($s));
    }

    public function testSearchSectionRendersDayAgainstBaseline(): void
    {
        $s = self::summary(['search' => [
            'configured' => true, 'date' => '2026-10-14',
            'day' => ['clicks' => 120, 'impressions' => 4000, 'ctr' => 0.03, 'avg_position' => 8.2],
            'baseline' => ['from' => '2026-10-07', 'to' => '2026-10-13', 'days' => 7, 'clicks' => 100.0, 'impressions' => 3500.0, 'ctr' => 0.0286, 'avg_position' => 9.0],
            'top_queries' => [['dimension_value' => 'gst <billing>', 'clicks' => 30, 'impressions' => 300, 'ctr' => 0.1, 'avg_position' => 5.5]],
            'top_pages' => [['dimension_value' => 'https://aicountly.com/billing', 'clicks' => 50, 'impressions' => 900, 'ctr' => 0.055, 'avg_position' => 4.0]],
            'movers' => ['improved' => [['query' => 'tally alternative', 'clicks' => 3, 'impressions' => 40, 'position' => 6.0, 'previous_position' => 11.0, 'change' => 5.0]], 'declined' => []],
        ]]);

        $html = DailyReportRenderer::html($s);
        $this->assertStringContainsString('+20%', $html);
        $this->assertStringContainsString('gst &lt;billing&gt;', $html, 'Query text is escaped.');
        $this->assertStringContainsString('aicountly.com/billing', $html);
        $this->assertStringContainsString('Biggest gains', $html);
        $this->assertStringContainsString('120 search clicks (+20% vs 7d)', DailyReportRenderer::subject($s));
    }

    public function testTodoRules(): void
    {
        $items = DailyReportBuilder::todo([
            'search' => [
                'configured' => true, 'date' => '2026-10-14',
                'day' => ['clicks' => 50], 'baseline' => ['clicks' => 100.0],
                'movers' => ['declined' => [['query' => 'gst invoice format', 'position' => 14.0, 'previous_position' => 6.0]]],
                'top_queries' => [['dimension_value' => 'gst billing software', 'avg_position' => 6.4, 'impressions' => 800, 'clicks' => 4]],
                'top_pages' => [['dimension_value' => 'https://aicountly.com/x', 'impressions' => 500, 'ctr' => 0.004, 'clicks' => 2]],
            ],
            'seo_tracker' => ['configured' => true, 'beaten' => [['keyword' => 'tally alternative', 'competitor' => 'Vyapar', 'competitor_rank' => 2, 'our_rank' => null]]],
            'pipeline' => ['dead_letters' => 3],
            'vetting' => ['configured' => true, 'counts' => ['approve' => 1, 'reject' => 4]],
            'content' => ['total' => 0],
        ]);

        $joined = implode("\n", $items);
        $this->assertLessThanOrEqual(8, count($items));
        $this->assertStringContainsString('-50%', $joined);
        $this->assertStringContainsString('"gst invoice format"', $joined);
        $this->assertStringContainsString('top 3', $joined);
        $this->assertStringContainsString('Vyapar outranks us for "tally alternative" (#2 vs not in top 100)', $joined);
        $this->assertStringContainsString('3 job(s) are dead-lettered', $joined);
    }

    public function testTodoItemsAreObservationsNotChores(): void
    {
        $joined = implode("\n", DailyReportBuilder::todo([
            'search' => [
                'configured' => true, 'date' => '2026-10-14',
                'day' => ['clicks' => 50], 'baseline' => ['clicks' => 100.0],
                'movers' => ['declined' => [['query' => 'gst invoice format', 'position' => 14.0, 'previous_position' => 6.0]]],
                'top_queries' => [['dimension_value' => 'gst billing software', 'avg_position' => 6.4, 'impressions' => 800, 'clicks' => 4]],
                'top_pages' => [['dimension_value' => 'https://aicountly.com/x', 'impressions' => 500, 'ctr' => 0.004, 'clicks' => 2]],
            ],
            'seo_tracker' => ['configured' => true, 'beaten' => [['keyword' => 'tally alternative', 'competitor' => 'Vyapar', 'competitor_rank' => 2, 'our_rank' => null]]],
            'vetting' => ['configured' => true, 'counts' => ['approve' => 1, 'reject' => 4]],
        ]));

        foreach (['Refresh the page', 'strengthen that page', 'Rewrite the title', 'plan content', 'adjust the routine prompts'] as $chore) {
            $this->assertStringNotContainsString($chore, $joined, 'routine content work is not a person\'s to-do');
        }
    }

    public function testTheContentCleanUpLineIsInformational(): void
    {
        $line = DailyReportRenderer::qualityLine(['checked' => 9, 'failed' => 2, 'unpublished' => 1, 'queued_regeneration' => 2, 'rewrites_open' => 3]);
        $this->assertSame('Content clean-up (automatic, no action needed): 9 re-checked · 2 failed · 1 taken down · 2 rewrites queued · 3 rewrites open', $line);
    }

    public function testTodoAsksForMissingConnections(): void
    {
        $items = DailyReportBuilder::todo([
            'search' => ['configured' => false],
            'seo_tracker' => ['configured' => false, 'status' => 'not_configured'],
        ]);

        $this->assertStringContainsString('SEARCH_CONSOLE_SETUP.md', $items[0]);
        $this->assertStringContainsString('DataForSEO', $items[1]);
    }
}
