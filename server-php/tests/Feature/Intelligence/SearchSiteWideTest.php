<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\SearchConsoleService;
use App\Libraries\Intelligence\SearchSiteAnalyticsService;
use App\Libraries\Intelligence\SearchSiteFactStore;
use App\Libraries\Intelligence\Seo\SeoSnapshotService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Site-wide Search Console storage: every row is kept (not only blog-mapped
 * ones), daily totals come from the property-level pass, and the SEO
 * snapshot finds the freshest ingested day instead of a fixed `-2 days`.
 */
final class SearchSiteWideTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
    }

    protected function tearDown(): void
    {
        ConnectorProviderFactory::useMocks(false);
        parent::tearDown();
    }

    private function connection(string $property = 'sc-domain:example.com'): int
    {
        $db = Database::connect();
        $db->table('reach_analytics_connections')->insert([
            'tenant_id' => 1, 'provider' => 'gsc', 'display_name' => 'GSC', 'site_property' => $property, 'enabled' => true,
        ]);

        return (int) $db->insertID();
    }

    public function testIngestionKeepsUnmappedRowsSiteWideAndWritesDailyTotals(): void
    {
        ConnectorProviderFactory::useMocks(true);
        $id = $this->connection();

        $result = SearchConsoleService::make()->ingestIncremental($id);

        $db = Database::connect();
        $this->assertSame(0, $result['ingested'], 'No blog identity claims the mock page.');
        $this->assertGreaterThan(0, $result['skipped']);
        $this->assertSame($result['skipped'], $result['site_rows'], 'Every skipped row is still stored site-wide.');
        $this->assertGreaterThan(0, $result['daily_rows']);
        $this->assertSame($result['site_rows'], (int) $db->table('reach_search_site_facts')->countAllResults());
        $this->assertSame(0, (int) $db->table('reach_search_metric_facts')->countAllResults());

        // Re-ingesting an overlapping window updates rows, it does not duplicate
        // them: the mock emits one row per day, so rows == distinct days.
        SearchConsoleService::make()->ingestIncremental($id);
        $distinct = (int) $db->query('SELECT COUNT(DISTINCT metric_date) AS c FROM reach_search_site_facts')->getRowArray()['c'];
        $this->assertSame($distinct, (int) $db->table('reach_search_site_facts')->countAllResults());
        $this->assertSame($distinct, (int) $db->table('reach_search_site_daily')->countAllResults());
    }

    /**
     * Production (17 Oct): a 90-day backfill-run printed seven real slices and
     * a bogus eighth `?..? site_rows=0 status=completed`, and status kept
     * reporting is_backfill_active=true with 0 days remaining — Postgres
     * returns booleans as 't'/'f' and (bool) 'f' is true.
     */
    public function testBackfillStopsAfterTheLastSliceAndClearsTheActiveFlag(): void
    {
        ConnectorProviderFactory::useMocks(true);
        $id  = $this->connection();
        $svc = SearchConsoleService::make();
        $svc->initiateBackfill($id, 28);

        $first = $svc->runBackfillChunk($id);
        $this->assertSame('in_progress', $first['status']);
        $this->assertNotNull($first['chunk_from']);

        $last = $svc->runBackfillChunk($id);
        $this->assertSame('completed', $last['status'], 'the final real slice reports completion itself');
        $this->assertNotNull($last['chunk_from']);
        $this->assertSame(0, $last['days_remaining']);

        $this->assertSame('inactive', $svc->runBackfillChunk($id)['status'], 'no extra slice after the last one');

        $status = $svc->connectionStatus($id);
        $cursor = $status['cursor'] ?? $status;
        $this->assertFalse($cursor['is_backfill_active']);
        $this->assertSame(0, $cursor['backfill_days_remaining']);
    }

    public function testAggregatesAndPositionBuckets(): void
    {
        $id    = $this->connection();
        $store = new SearchSiteFactStore();
        $store->upsertFacts($id, 'sc-domain:example.com', null, [
            ['metric_date' => '2026-10-10', 'page_url' => 'https://example.com/', 'query' => 'aicountly', 'clicks' => 40, 'impressions' => 100, 'ctr' => 0.4, 'avg_position' => 1.2],
            ['metric_date' => '2026-10-10', 'page_url' => 'https://example.com/billing', 'query' => 'gst billing software', 'clicks' => 5, 'impressions' => 500, 'ctr' => 0.01, 'avg_position' => 8.0],
            ['metric_date' => '2026-10-11', 'page_url' => 'https://example.com/billing', 'query' => 'gst billing software', 'clicks' => 7, 'impressions' => 500, 'ctr' => 0.014, 'avg_position' => 6.0],
            ['metric_date' => '2026-10-11', 'page_url' => 'https://example.com/inventory', 'query' => 'stock software', 'clicks' => 0, 'impressions' => 30, 'ctr' => 0, 'avg_position' => 35.0],
            // duplicate key inside one batch must not abort the upsert
            ['metric_date' => '2026-10-11', 'page_url' => 'https://example.com/inventory', 'query' => 'stock software', 'clicks' => 1, 'impressions' => 31, 'ctr' => 0.03, 'avg_position' => 34.0],
        ]);
        $store->upsertDaily($id, 'sc-domain:example.com', null, [
            ['metric_date' => '2026-10-11', 'device' => 'DESKTOP', 'clicks' => 6, 'impressions' => 400, 'avg_position' => 7.0],
            ['metric_date' => '2026-10-11', 'device' => 'MOBILE', 'clicks' => 4, 'impressions' => 200, 'avg_position' => 10.0],
        ]);

        $svc = new SearchSiteAnalyticsService();
        $this->assertSame('2026-10-11', $svc->latestDate());

        $days = $svc->daily('2026-10-10', '2026-10-11');
        $this->assertSame('facts', $days[0]['source'], '10-10 only has row-level facts.');
        $this->assertSame(45, $days[0]['clicks']);
        $this->assertSame('daily', $days[1]['source'], 'Property-level totals win when present.');
        $this->assertSame(10, $days[1]['clicks']);
        $this->assertEqualsWithDelta(8.0, $days[1]['avg_position'], 0.01, 'Impression-weighted: (7*400+10*200)/600.');

        $queries = $svc->topQueries('2026-10-10', '2026-10-11', 10);
        $this->assertSame('aicountly', $queries[0]['dimension_value']);
        $this->assertSame(12, $queries[1]['clicks']);

        $buckets = array_column($svc->positionBuckets('2026-10-10', '2026-10-11'), null, 'bucket');
        $this->assertSame(1, $buckets['1-3']['queries']);
        $this->assertSame(1, $buckets['4-10']['queries']);
        $this->assertSame(1, $buckets['21-50']['queries']);

        $movers = $svc->movers('2026-10-11', '2026-10-04', '2026-10-10');
        $this->assertSame('gst billing software', $movers['improved'][0]['query']);
        $this->assertEqualsWithDelta(2.0, $movers['improved'][0]['change'], 0.01);
    }

    public function testSnapshotUsesFreshestIngestedDayAndPlannedKeywords(): void
    {
        $id   = $this->connection();
        $date = date('Y-m-d', strtotime('-4 days'));
        (new SearchSiteFactStore())->upsertFacts($id, 'sc-domain:example.com', null, [
            ['metric_date' => $date, 'page_url' => 'https://example.com/pricing', 'query' => 'gst billing software india', 'clicks' => 3, 'impressions' => 90, 'avg_position' => 7.5],
            // Inside the data lag: must never be snapshotted as a complete day.
            ['metric_date' => date('Y-m-d', strtotime('-1 day')), 'page_url' => 'https://example.com/pricing', 'query' => 'gst billing software', 'clicks' => 1, 'impressions' => 9, 'avg_position' => 7.0],
        ]);

        $db = Database::connect();
        $db->table('reach_keyword_ideas')->insert(['keyword' => 'gst billing software', 'status' => 'planned', 'priority' => 'high']);
        $db->table('reach_keyword_ideas')->insert(['keyword' => 'archived term', 'status' => 'archived', 'priority' => 'low']);

        $result = (new SeoSnapshotService())->captureRankSnapshots();

        $this->assertSame($date, $result['date']);
        $this->assertSame('reach_search_site_facts', $result['source']);
        $this->assertSame(1, $result['keywords_tracked'], "'planned' counts; 'archived' does not.");
        $this->assertSame(1, $result['snapshots_written']);
        $row = $db->table('reach_seo_rank_snapshots')->get()->getRowArray();
        $this->assertSame('https://example.com/pricing', $row['best_page_url']);
    }

    public function testSiteEndpointsAnswerHonestlyWhenEmpty(): void
    {
        $svc = new SearchSiteAnalyticsService();
        $this->assertTrue($svc->isAvailable());
        $this->assertNull($svc->latestDate());
        $this->assertSame([], $svc->daily('2026-01-01', '2026-12-31'));
        $this->assertSame(0, $svc->totals('2026-01-01', '2026-12-31')['clicks']);

        $result = (new SeoSnapshotService())->captureRankSnapshots();
        $this->assertNull($result['date']);
        $this->assertSame(0, $result['snapshots_written']);
    }
}
