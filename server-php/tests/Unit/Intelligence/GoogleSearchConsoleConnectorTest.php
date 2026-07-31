<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use App\Libraries\Intelligence\Connectors\MockSearchConsoleConnector;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure-unit coverage for the live Search Console connector. No network calls:
 * every assertion targets configuration state, date clamping, dimension mapping
 * or row normalisation.
 */
final class GoogleSearchConsoleConnectorTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        ConnectorProviderFactory::useMocks(false);
        unset($_ENV['SEARCH_CONSOLE_USE_MOCK'], $_ENV['SEARCH_CONSOLE_ENABLED']);
        putenv('SEARCH_CONSOLE_USE_MOCK');
        putenv('SEARCH_CONSOLE_ENABLED');
        parent::tearDown();
    }

    public function testProviderNameIsStable(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);
        $this->assertSame('google_search_console', $connector->providerName());
    }

    public function testDisabledConnectorReportsDisabledState(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);

        $this->assertFalse($connector->isEnabled());
        $this->assertFalse($connector->isConfigured());
        $this->assertSame(
            GoogleSearchConsoleConnector::STATUS_DISABLED,
            $connector->configurationState()['status'],
        );
    }

    public function testEnabledWithoutKeyPathReportsMisconfigured(): void
    {
        $connector = new GoogleSearchConsoleConnector(
            enabled: true,
            keyPath: '',
            siteProperty: 'sc-domain:aicountly.com',
        );

        $state = $connector->configurationState();

        $this->assertSame(GoogleSearchConsoleConnector::STATUS_MISCONFIGURED, $state['status']);
        $this->assertStringContainsString('KEY_PATH', $state['detail']);
        $this->assertFalse($connector->isConfigured());
    }

    public function testEnabledWithMissingKeyFileReportsMisconfigured(): void
    {
        $connector = new GoogleSearchConsoleConnector(
            enabled: true,
            keyPath: '/tmp/definitely-not-a-real-gsc-key-' . bin2hex(random_bytes(6)) . '.json',
            siteProperty: 'sc-domain:aicountly.com',
        );

        $state = $connector->configurationState();

        $this->assertSame(GoogleSearchConsoleConnector::STATUS_MISCONFIGURED, $state['status']);
        $this->assertStringContainsString('does not exist', $state['detail']);
    }

    public function testDisabledConnectorReturnsEmptyBatchWithWarningInsteadOfThrowing(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);

        $batch = $connector->fetchSearchMetrics(new IngestionRequest(
            connectionId: 1,
            streamType: 'search_metrics',
            dateFrom: '2026-07-01',
            dateTo: '2026-07-10',
        ));

        $this->assertSame(0, $batch->rowCount);
        $this->assertSame([], $batch->rows);
        $this->assertTrue($batch->isComplete);
        $this->assertNotNull($batch->warningMessage);
    }

    public function testHealthCheckOnDisabledConnectorFailsWithoutNetwork(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);
        $result    = $connector->healthCheck();

        $this->assertFalse($result->healthy);
        $this->assertSame(GoogleSearchConsoleConnector::STATUS_DISABLED, $result->errorClass);
    }

    public function testDateWindowIsClampedToTheDataLag(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false, dataLagDays: 3);
        $today     = new \DateTimeImmutable('today');
        $expected  = $today->modify('-3 days')->format('Y-m-d');

        [$from, $to] = $connector->resolveDateWindow(
            $today->modify('-30 days')->format('Y-m-d'),
            $today->format('Y-m-d'),
        );

        $this->assertSame($expected, $to, 'End date must be clamped to now minus the data lag.');
        $this->assertSame($today->modify('-30 days')->format('Y-m-d'), $from);
    }

    public function testDateWindowNeverExceedsApiRangeLimit(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false, dataLagDays: 0);

        [$from, $to] = $connector->resolveDateWindow('2000-01-01', (new \DateTimeImmutable('today'))->format('Y-m-d'));

        $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
        $this->assertLessThanOrEqual(490, $days);
    }

    public function testWindowFullyInsideLagYieldsEmptyBatchNotFabricatedRows(): void
    {
        $connector = new GoogleSearchConsoleConnector(
            enabled: true,
            keyPath: '',
            siteProperty: 'sc-domain:aicountly.com',
            dataLagDays: 3,
        );

        $today = new \DateTimeImmutable('today');
        $batch = $connector->fetchSearchMetrics(new IngestionRequest(
            connectionId: 1,
            streamType: 'search_metrics',
            dateFrom: $today->format('Y-m-d'),
            dateTo: $today->format('Y-m-d'),
        ));

        $this->assertSame(0, $batch->rowCount);
        $this->assertSame([], $batch->rows);
    }

    public function testDimensionsAlwaysIncludeDateAndDropUnknownValues(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);

        $this->assertSame(['date', 'page', 'query'], $connector->resolveDimensions([]));
        $this->assertSame(['date', 'query'], $connector->resolveDimensions(['query']));
        $this->assertSame(['date', 'page'], $connector->resolveDimensions(['page_url']));
        $this->assertSame(
            ['date', 'device'],
            $connector->resolveDimensions(['device', 'not_a_dimension']),
        );
    }

    public function testDimensionsAreDeduplicated(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);

        $this->assertSame(['date', 'page'], $connector->resolveDimensions(['page', 'page_url', 'page']));
    }

    public function testRowNormalisationMapsKeysByDimensionOrder(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);

        $row = $connector->normaliseRow(
            [
                'keys'        => ['2026-07-20', 'https://aicountly.com/blogs/gst-returns', 'gst return due date'],
                'clicks'      => 14,
                'impressions' => 420,
                'ctr'         => 0.0333333333,
                'position'    => 6.789,
            ],
            ['date', 'page', 'query'],
            '2026-07-01',
        );

        $this->assertSame('2026-07-20', $row['metric_date']);
        $this->assertSame('https://aicountly.com/blogs/gst-returns', $row['page_url']);
        $this->assertSame('gst return due date', $row['query']);
        $this->assertSame(14, $row['clicks']);
        $this->assertSame(420, $row['impressions']);
        $this->assertSame(6.79, $row['avg_position']);
        $this->assertNull($row['device']);
    }

    public function testRowNormalisationFallsBackToWindowDateWhenDateMissing(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: false);

        $row = $connector->normaliseRow(['keys' => ['gst'], 'clicks' => 1], ['query'], '2026-07-05');

        $this->assertSame('2026-07-05', $row['metric_date']);
        $this->assertSame(0, $row['impressions']);
    }

    public function testCapabilitiesExposeDataLagAndProviderName(): void
    {
        $connector    = new GoogleSearchConsoleConnector(enabled: false, dataLagDays: 4);
        $capabilities = $connector->getCapabilities();

        $this->assertSame('google_search_console', $capabilities['provider_name']);
        $this->assertSame(4, $capabilities['data_lag_days']);
        $this->assertTrue($capabilities['supports_pagination']);
        $this->assertContains('page', $capabilities['supported_dimensions']);
    }

    public function testUnconfiguredConnectorReportsNoSitePropertiesWithoutNetwork(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: true, keyPath: '', siteProperty: '');

        $this->assertSame([], $connector->getSiteProperties());
        $this->assertFalse($connector->validateSiteProperty('sc-domain:aicountly.com'));
        $this->assertFalse($connector->validateSiteProperty(''));
    }

    public function testFactoryReturnsLiveConnectorByDefault(): void
    {
        ConnectorProviderFactory::useMocks(false);

        $this->assertInstanceOf(
            GoogleSearchConsoleConnector::class,
            ConnectorProviderFactory::searchConsole(),
        );
    }

    public function testFactoryReturnsMockWhenExplicitlyRequested(): void
    {
        ConnectorProviderFactory::useMocks(true);

        $this->assertInstanceOf(
            MockSearchConsoleConnector::class,
            ConnectorProviderFactory::searchConsole(),
        );
    }

    public function testFactoryHonoursUseMockEnvironmentFlag(): void
    {
        ConnectorProviderFactory::useMocks(false);
        $_ENV['SEARCH_CONSOLE_USE_MOCK'] = 'true';
        putenv('SEARCH_CONSOLE_USE_MOCK=true');

        $this->assertInstanceOf(
            MockSearchConsoleConnector::class,
            ConnectorProviderFactory::searchConsole(),
        );
    }
}
