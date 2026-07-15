<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Libraries\Intelligence\Connectors\DTOs\MetricBatch;
use App\Libraries\Intelligence\Connectors\MockSearchConsoleConnector;
use App\Libraries\Intelligence\Connectors\MockContentAnalyticsConnector;
use App\Libraries\Intelligence\Connectors\MockIndexNowConnector;
use Config\Permissions;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ConnectorContractsTest extends CIUnitTestCase
{
    public function testPhase8PermissionSlugsAreTwoSegments(): void
    {
        $phase8Groups = [
            'intelligence', 'search', 'sitemap', 'attribution',
            'visibility', 'competitor', 'connector',
        ];

        foreach ($phase8Groups as $group) {
            $perms = Permissions::groups()[$group] ?? null;
            $this->assertNotNull($perms, "Group '{$group}' must exist in Permissions::groups()");

            foreach ($perms as $slug) {
                $parts = explode('.', $slug);
                $this->assertCount(2, $parts, "Permission slug '{$slug}' must have exactly 2 segments");
            }
        }
    }

    public function testPhase8PermissionSlugsAreUnique(): void
    {
        $all  = Permissions::all();
        $unique = array_unique($all);
        $this->assertCount(count($unique), $all, 'All permission slugs must be unique');
    }

    public function testPhase8AnalyticsGroupContainsBothLegacyAndNewPermissions(): void
    {
        $group = Permissions::groups()['analytics'];
        $this->assertContains('analytics.view', $group, 'analytics.view must remain for backwards compatibility');
        $this->assertContains('analytics.read', $group);
        $this->assertContains('analytics.connect', $group);
        $this->assertContains('analytics.ingest', $group);
    }

    public function testMockSearchConsoleConnectorImplementsInterface(): void
    {
        $connector = new MockSearchConsoleConnector(enabled: true);
        $this->assertSame('mock_gsc', $connector->providerName());
        $this->assertTrue($connector->isEnabled());
    }

    public function testMockSearchConsoleHealthyWhenEnabled(): void
    {
        $connector = new MockSearchConsoleConnector(enabled: true);
        $result    = $connector->healthCheck();
        $this->assertInstanceOf(ConnectorHealthResult::class, $result);
        $this->assertTrue($result->healthy);
        $this->assertSame('healthy', $result->status);
        $this->assertGreaterThan(0, $result->latencyMs);
    }

    public function testMockSearchConsoleFailingWhenConfigured(): void
    {
        $connector = new MockSearchConsoleConnector(enabled: true, shouldFail: true);
        $result    = $connector->healthCheck();
        $this->assertFalse($result->healthy);
        $this->assertSame('AuthFailure', $result->errorClass);
    }

    public function testMockSearchConsoleFetchesMetrics(): void
    {
        $connector = new MockSearchConsoleConnector(enabled: true);
        $request   = new IngestionRequest(
            connectionId: 1,
            streamType: 'search_metrics',
            dateFrom: '2026-07-01',
            dateTo: '2026-07-03',
        );

        $batch = $connector->fetchSearchMetrics($request);
        $this->assertInstanceOf(MetricBatch::class, $batch);
        $this->assertTrue($batch->isComplete);
        $this->assertGreaterThan(0, $batch->rowCount);

        $row = $batch->rows[0];
        $this->assertArrayHasKey('metric_date', $row);
        $this->assertArrayHasKey('clicks', $row);
        $this->assertArrayHasKey('impressions', $row);
        $this->assertArrayHasKey('avg_position', $row);
    }

    public function testMockSearchConsoleThrowsOnFetchWhenShouldFail(): void
    {
        $connector = new MockSearchConsoleConnector(enabled: true, shouldFail: true);
        $this->expectException(\RuntimeException::class);
        $connector->fetchSearchMetrics(new IngestionRequest(
            connectionId: 1,
            streamType: 'search_metrics',
            dateFrom: '2026-07-01',
            dateTo: '2026-07-01',
        ));
    }

    public function testMockContentAnalyticsConnectorImplementsInterface(): void
    {
        $connector = new MockContentAnalyticsConnector(enabled: true);
        $this->assertSame('mock_ga4', $connector->providerName());
        $this->assertTrue($connector->isEnabled());
    }

    public function testMockContentAnalyticsFetchesContentMetrics(): void
    {
        $connector = new MockContentAnalyticsConnector(enabled: true, pageMap: ['/about' => 'https://example.com/about']);
        $request   = new IngestionRequest(
            connectionId: 1,
            streamType: 'content_metrics',
            dateFrom: '2026-07-01',
            dateTo: '2026-07-02',
        );

        $batch = $connector->fetchContentMetrics($request);
        $this->assertGreaterThan(0, $batch->rowCount);
        $row = $batch->rows[0];
        $this->assertArrayHasKey('sessions', $row);
        $this->assertArrayHasKey('engaged_sessions', $row);
        $this->assertArrayHasKey('engagement_rate', $row);
    }

    public function testMockContentAnalyticsResolvesPage(): void
    {
        $connector = new MockContentAnalyticsConnector(pageMap: ['/blog/test' => 'https://example.com/blog/test']);
        $this->assertSame('https://example.com/blog/test', $connector->resolvePageToIdentity('/blog/test'));
        $this->assertNull($connector->resolvePageToIdentity('/nonexistent'));
    }

    public function testMockIndexNowSubmitsSuccessfully(): void
    {
        $connector = new MockIndexNowConnector(enabled: true);
        $result    = $connector->submitUrl('https://example.com/page', 'testkey');
        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_status']);
        $this->assertContains('https://example.com/page', $connector->getSubmittedUrls());
    }

    public function testMockIndexNowBatchSubmit(): void
    {
        $connector = new MockIndexNowConnector(enabled: true);
        $urls      = ['https://example.com/p1', 'https://example.com/p2'];
        $result    = $connector->submitBatch($urls, 'testkey');
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['accepted']);
    }

    public function testMockIndexNowRateLimitScenario(): void
    {
        $connector = new MockIndexNowConnector(enabled: true, shouldRateLimit: true);
        $result    = $connector->submitUrl('https://example.com/page', 'k');
        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['http_status']);
        $this->assertArrayHasKey('retry_after_secs', $result);
    }

    public function testConnectorProviderFactoryReturnsMocksWhenSet(): void
    {
        ConnectorProviderFactory::useMocks(true);
        $gsc  = ConnectorProviderFactory::searchConsole();
        $ga4  = ConnectorProviderFactory::contentAnalytics();
        $inow = ConnectorProviderFactory::indexNow();

        $this->assertInstanceOf(\App\Libraries\Intelligence\Connectors\MockSearchConsoleConnector::class, $gsc);
        $this->assertInstanceOf(\App\Libraries\Intelligence\Connectors\MockContentAnalyticsConnector::class, $ga4);
        $this->assertInstanceOf(\App\Libraries\Intelligence\Connectors\MockIndexNowConnector::class, $inow);
        ConnectorProviderFactory::useMocks(false);
    }
}
