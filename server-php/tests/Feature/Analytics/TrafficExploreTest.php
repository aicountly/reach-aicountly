<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Libraries\AnalyticsCache;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\TrafficAnalyticsService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * GA4 traffic explorer (GET v1/analytics/traffic/explore): each drill step is
 * one runReport narrowed by exact matches, cached like the overview, and an
 * unconfigured stream returns an empty flagged payload — never numbers.
 */
final class TrafficExploreTest extends DatabaseTestCase
{
    /** @var list<array{stream: string, body: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        $this->calls = [];
    }

    /** @param array<string, array<string, mixed>|null> $reports stream => runReport response */
    private function service(array $reports): TrafficAnalyticsService
    {
        return new TrafficAnalyticsService(new AnalyticsCache(), function (string $stream, array $body) use ($reports): ?array {
            $this->calls[] = ['stream' => $stream, 'body' => $body];

            return $reports[$stream] ?? null;
        });
    }

    /** @param list<array{0: string, 1: list<int|float>}> $rows */
    private static function report(array $rows, ?array $totals = null, ?int $rowCount = null): array
    {
        $metric = static fn (array $values): array => array_map(static fn ($v): array => ['value' => (string) $v], $values);

        return [
            'rows'     => array_map(static fn (array $r): array => [
                'dimensionValues' => [['value' => $r[0]]],
                'metricValues'    => $metric($r[1]),
            ], $rows),
            'rowCount' => $rowCount ?? count($rows),
            'totals'   => $totals !== null ? [['metricValues' => $metric($totals)]] : [],
        ];
    }

    public function testUnconfiguredStreamReturnsAnEmptyFlaggedPayload(): void
    {
        $result = (new TrafficAnalyticsService())->explore('date', 30, 'marketing_site', ['channel' => 'Organic Search']);

        $this->assertTrue($result['unconfigured']);
        $this->assertTrue($result['data']['_unconfigured']);
        $this->assertStringContainsString('property ID is missing', $result['data']['_reason']);
        $this->assertSame([], $result['data']['rows']);
        $this->assertSame(0, $result['data']['totals']['sessions']);
        $this->assertSame(['channel' => 'Organic Search'], $result['data']['filters']);
    }

    public function testOneReportPerStepNarrowedByExactMatchesAndCached(): void
    {
        $svc = $this->service([
            'marketing_site' => self::report(
                [['/pricing', [40, 30, 90, 0.25, 300]], ['/blogs/gst-guide', [10, 9, 12, 0.5, 40]]],
                [50, 38, 102, 0.3, 340],
                2,
            ),
        ]);

        $out = $svc->explore('page', 30, 'marketing_site', [
            'date' => '2026-10-20', 'channel' => 'Organic Search', 'device' => 'mobile', 'unknown' => 'x',
        ], ['sort' => 'pageviews', 'limit' => 10]);

        $this->assertCount(1, $this->calls, 'Rows and totals come from one runReport.');
        $body = $this->calls[0]['body'];
        $this->assertSame([['startDate' => '2026-10-20', 'endDate' => '2026-10-20']], $body['dateRanges']);
        $this->assertSame([['name' => 'pagePath']], $body['dimensions']);
        $this->assertSame(['TOTAL'], $body['metricAggregations']);
        $this->assertSame(10, $body['limit']);
        $this->assertSame(['metric' => ['metricName' => 'screenPageViews'], 'desc' => true], $body['orderBys'][0]);

        $exact = array_map(static fn (array $e): array => [$e['filter']['fieldName'], $e['filter']['stringFilter']['matchType'], $e['filter']['stringFilter']['value']],
            $body['dimensionFilter']['andGroup']['expressions']);
        $this->assertSame([
            ['date', 'EXACT', '20261020'],
            ['sessionDefaultChannelGrouping', 'EXACT', 'Organic Search'],
            ['deviceCategory', 'EXACT', 'mobile'],
        ], $exact);

        $data = $out['data'];
        $this->assertFalse($out['unconfigured']);
        $this->assertSame('page', $data['group_by']);
        $this->assertSame(2, $data['total_rows']);
        $this->assertSame(['dimension_value' => '/pricing', 'sessions' => 40, 'users' => 30, 'pageviews' => 90, 'bounce_rate' => 25.0, 'events' => 300], $data['rows'][0]);
        $this->assertSame(50, $data['totals']['sessions']);
        $this->assertSame(30.0, $data['totals']['bounce_rate']);
        $this->assertSame(['from' => '2026-10-20', 'to' => '2026-10-20'], $data['period']);
        $this->assertSame('https://aicountly.com', $data['site']);

        $again = $svc->explore('page', 30, 'marketing_site', [
            'date' => '20261020', 'channel' => 'Organic Search', 'device' => 'mobile',
        ], ['sort' => 'pageviews', 'limit' => 10]);
        $this->assertTrue($again['cached']);
        $this->assertCount(1, $this->calls, 'A repeated step is served from AnalyticsCache.');
    }

    public function testContainsFilterNarrowsRowsButNotTheSliceTotals(): void
    {
        $svc = $this->service(['portal' => self::report([['google / organic', [5, 4, 9, 0.2, 11]]], [5, 4, 9, 0.2, 11])]);

        $svc->explore('source', 30, 'portal', ['event' => 'lead_form_submit'], ['search' => 'goo']);

        $this->assertCount(2, $this->calls);
        $rowsFilter = $this->calls[0]['body']['dimensionFilter']['andGroup']['expressions'];
        $this->assertSame('CONTAINS', $rowsFilter[1]['filter']['stringFilter']['matchType']);
        $this->assertSame('sessionSourceMedium', $rowsFilter[1]['filter']['fieldName']);
        $totalsBody = $this->calls[1]['body'];
        $this->assertArrayNotHasKey('dimensions', $totalsBody);
        $this->assertSame('eventName', $totalsBody['dimensionFilter']['filter']['fieldName']);
    }

    public function testAllSitesMergesPropertiesPerValue(): void
    {
        $svc = $this->service([
            'marketing_site' => self::report([['Organic Search', [30, 20, 60, 0.5, 100]], ['Direct', [10, 8, 15, 0.2, 30]]], [40, 28, 75, 0.425, 130]),
            'portal'         => self::report([['Organic Search', [10, 9, 20, 0.1, 40]]], [10, 9, 20, 0.1, 40]),
        ]);

        $data = $svc->explore('channel', 30, 'all', [], ['limit' => 1])['data'];

        $this->assertSame(2, $data['total_rows']);
        $this->assertFalse($data['total_rows_approx']);
        $this->assertCount(1, $data['rows'], 'Paged after merging.');
        $row = $data['rows'][0];
        $this->assertSame('Organic Search', $row['dimension_value']);
        $this->assertSame(40, $row['sessions']);
        $this->assertSame(29, $row['users']);
        $this->assertSame(40.0, $row['bounce_rate'], 'Session-weighted: (0.5×30 + 0.1×10) / 40.');
        $this->assertSame(['https://aicountly.com', 'https://reach.aicountly.org'], $row['sites']);
        $this->assertSame(50, $data['totals']['sessions']);
    }

    public function testPageRowsCarryTheContentIdentityPublishedAtThatUrl(): void
    {
        Database::connect()->table('reach_content_identities')->insert([
            'tenant_id' => 1, 'content_type' => 'page', 'source_id' => 7,
            'canonical_url' => 'https://aicountly.com/blogs/gst-guide/',
        ]);
        $identityId = (int) Database::connect()->insertID();

        $svc  = $this->service(['marketing_site' => self::report([['/blogs/gst-guide', [3, 3, 4, 0, 5]], ['/pricing', [2, 2, 2, 0, 2]]], [5, 5, 6, 0, 7])]);
        $rows = $svc->explore('page', 30, 'marketing_site')['data']['rows'];
        $this->assertSame($identityId, $rows[0]['content_identity_id']);
        $this->assertNull($rows[1]['content_identity_id']);

        $leaf = $svc->explore('date', 30, 'marketing_site', ['page' => '/blogs/gst-guide'])['data'];
        $this->assertSame($identityId, $leaf['page_content_identity_id']);
    }

    public function testRejectsUnknownDimensionsAndBadDates(): void
    {
        $svc = $this->service([]);
        try {
            $svc->explore('landing', 30, 'all');
            $this->fail('Unknown dimension accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('landing', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $svc->explore('page', 30, 'all', ['date' => '2026-13-40']);
    }
}
