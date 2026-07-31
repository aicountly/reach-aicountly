<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleAnalyticsContentConnector;
use App\Libraries\Intelligence\Connectors\MockContentAnalyticsConnector;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure-unit coverage for the live GA4 content-analytics connector. No network
 * calls: every assertion targets configuration state or row normalisation.
 *
 * Guards against regressing to the bug this connector fixed — the factory
 * used to always return MockContentAnalyticsConnector regardless of
 * configuration, so a configured GA4 property never actually reached the
 * ingestion pipeline.
 */
final class GoogleAnalyticsContentConnectorTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        ConnectorProviderFactory::useMocks(false);
        unset($_ENV['CONTENT_ANALYTICS_USE_MOCK'], $_ENV['CONTENT_ANALYTICS_ENABLED']);
        putenv('CONTENT_ANALYTICS_USE_MOCK');
        putenv('CONTENT_ANALYTICS_ENABLED');
        parent::tearDown();
    }

    public function testProviderNameIsStable(): void
    {
        $connector = new GoogleAnalyticsContentConnector(enabled: false);
        $this->assertSame('google_analytics_4', $connector->providerName());
    }

    public function testDisabledConnectorReportsDisabledState(): void
    {
        $connector = new GoogleAnalyticsContentConnector(enabled: false);

        $this->assertFalse($connector->isEnabled());
        $this->assertSame(
            GoogleAnalyticsContentConnector::STATUS_DISABLED,
            $connector->configurationState()['status'],
        );
    }

    public function testEnabledWithoutKeyPathReportsMisconfigured(): void
    {
        $connector = new GoogleAnalyticsContentConnector(
            enabled: true,
            keyPath: '',
            propertyId: '123456789',
        );

        $state = $connector->configurationState();

        $this->assertSame(GoogleAnalyticsContentConnector::STATUS_MISCONFIGURED, $state['status']);
        $this->assertStringContainsString('KEY_PATH', $state['detail']);
    }

    public function testEnabledWithMissingKeyFileReportsMisconfigured(): void
    {
        $connector = new GoogleAnalyticsContentConnector(
            enabled: true,
            keyPath: '/tmp/definitely-not-a-real-ga4-key-' . bin2hex(random_bytes(6)) . '.json',
            propertyId: '123456789',
        );

        $state = $connector->configurationState();

        $this->assertSame(GoogleAnalyticsContentConnector::STATUS_MISCONFIGURED, $state['status']);
        $this->assertStringContainsString('does not exist', $state['detail']);
    }

    public function testEnabledWithoutPropertyIdReportsMisconfigured(): void
    {
        $connector = new GoogleAnalyticsContentConnector(
            enabled: true,
            keyPath: '',
            propertyId: '',
        );

        $state = $connector->configurationState();
        $this->assertSame(GoogleAnalyticsContentConnector::STATUS_MISCONFIGURED, $state['status']);
    }

    public function testDisabledConnectorReturnsEmptyBatchWithWarningInsteadOfThrowing(): void
    {
        $connector = new GoogleAnalyticsContentConnector(enabled: false);

        $batch = $connector->fetchContentMetrics(new IngestionRequest(
            connectionId: 1,
            streamType: 'content_metrics',
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
        $connector = new GoogleAnalyticsContentConnector(enabled: false);
        $result    = $connector->healthCheck();

        $this->assertFalse($result->healthy);
        $this->assertSame(GoogleAnalyticsContentConnector::STATUS_DISABLED, $result->errorClass);
    }

    public function testListAvailablePropertiesEmptyWhenUnconfigured(): void
    {
        $connector = new GoogleAnalyticsContentConnector(enabled: false, propertyId: '');
        $this->assertSame([], $connector->listAvailableProperties());
    }

    public function testListAvailablePropertiesReflectsConfiguredId(): void
    {
        $connector = new GoogleAnalyticsContentConnector(enabled: false, propertyId: '123456789');
        $this->assertSame(['properties/123456789'], $connector->listAvailableProperties());
    }

    public function testResolvePageToIdentityReturnsPathOrNull(): void
    {
        $connector = new GoogleAnalyticsContentConnector(enabled: false);
        $this->assertSame('/blogs/gst-returns', $connector->resolvePageToIdentity('/blogs/gst-returns'));
        $this->assertNull($connector->resolvePageToIdentity(''));
    }

    public function testCapabilitiesExposeProviderNameAndDimensions(): void
    {
        $connector    = new GoogleAnalyticsContentConnector(enabled: false);
        $capabilities = $connector->getCapabilities();

        $this->assertSame('google_analytics_4', $capabilities['provider_name']);
        $this->assertContains('pagePath', $capabilities['supported_dimensions']);
    }

    public function testFactoryReturnsLiveConnectorByDefault(): void
    {
        ConnectorProviderFactory::useMocks(false);

        $this->assertInstanceOf(
            GoogleAnalyticsContentConnector::class,
            ConnectorProviderFactory::contentAnalytics(),
        );
    }

    public function testFactoryReturnsMockWhenExplicitlyRequested(): void
    {
        ConnectorProviderFactory::useMocks(true);

        $this->assertInstanceOf(
            MockContentAnalyticsConnector::class,
            ConnectorProviderFactory::contentAnalytics(),
        );
    }

    public function testFactoryHonoursUseMockEnvironmentFlag(): void
    {
        ConnectorProviderFactory::useMocks(false);
        $_ENV['CONTENT_ANALYTICS_USE_MOCK'] = 'true';
        putenv('CONTENT_ANALYTICS_USE_MOCK=true');

        $this->assertInstanceOf(
            MockContentAnalyticsConnector::class,
            ConnectorProviderFactory::contentAnalytics(),
        );
    }

    public function testFactoryLiveConnectorIsDisabledWithoutExplicitConfig(): void
    {
        ConnectorProviderFactory::useMocks(false);
        unset($_ENV['CONTENT_ANALYTICS_ENABLED']);
        putenv('CONTENT_ANALYTICS_ENABLED');

        $connector = ConnectorProviderFactory::contentAnalytics();
        $this->assertInstanceOf(GoogleAnalyticsContentConnector::class, $connector);
        $this->assertFalse($connector->isEnabled(), 'Must default to disabled — never fabricate GA4 data.');
    }
}
