<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\ContentIdentitySyncService;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use App\Libraries\Intelligence\Connectors\MockSearchConsoleConnector;
use App\Libraries\JobHandlerRegistry;
use App\Models\Intelligence\SearchMetricFactModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers the wiring that turned the Search Console connector from an isolated
 * client into a working pipeline. No network and no database: every assertion
 * targets pure normalisation, URL matching, property binding or registration.
 */
final class SearchConsoleWiringTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        ConnectorProviderFactory::useMocks(false);
        unset($_ENV['SEARCH_CONSOLE_USE_MOCK']);
        putenv('SEARCH_CONSOLE_USE_MOCK');
        parent::tearDown();
    }

    // --- Ingestion job registration -------------------------------------

    public function testIngestJobIsRegisteredSoTheSchedulerCanRunIt(): void
    {
        $registry = new JobHandlerRegistry();

        $this->assertTrue(
            $registry->has('reach.search_console_ingest'),
            'Without this handler nothing ever writes reach_search_metric_facts.',
        );
        $this->assertInstanceOf(
            \App\Jobs\SearchConsoleIngestJob::class,
            $registry->get('reach.search_console_ingest'),
        );
    }

    public function testSchedulerEnqueuesIngestBeforeTheSeoSnapshot(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachSchedule.php');

        $ingestHour   = strpos($source, "reach.search_console_ingest");
        $snapshotHour = strpos($source, "reach.seo_snapshot");

        $this->assertNotFalse($ingestHour, 'Ingest must be scheduled.');
        $this->assertNotFalse($snapshotHour);
        $this->assertLessThan(
            $snapshotHour,
            $ingestHour,
            'Ingest is scheduled at 04:00 and the snapshot aggregates its rows at 05:00.',
        );
    }

    // --- Fact-table migration -------------------------------------------

    /**
     * uq_search_metric_fact is not deferrable. If the sentinel backfill runs
     * first, two rows that differed only by a NULL both become 'ZZZ' and the
     * UPDATE aborts on a duplicate key — verified against Postgres 16. The
     * deletes must therefore precede the updates.
     */
    public function testMigrationDeduplicatesBeforeWritingSentinels(): void
    {
        $source = (string) file_get_contents(
            APPPATH . 'Database/Migrations/2026-08-06-100004_NormaliseSearchMetricFactKeys.php'
        );
        $up = explode('public function down', $source)[0];

        $firstDelete   = strpos($up, 'DELETE FROM reach_search_metric_facts');
        $firstSentinel = strpos($up, "SET country = 'ZZZ'");

        $this->assertNotFalse($firstDelete, 'The migration must collapse legacy duplicates.');
        $this->assertNotFalse($firstSentinel);
        $this->assertLessThan($firstDelete === false ? PHP_INT_MAX : $firstSentinel, $firstDelete);
    }

    public function testMigrationWidensCountryForGooglesAlpha3Codes(): void
    {
        $source = (string) file_get_contents(
            APPPATH . 'Database/Migrations/2026-08-06-100004_NormaliseSearchMetricFactKeys.php'
        );

        // CHAR(2) rejects 'IND' outright: "value too long for type character(2)".
        $this->assertStringContainsString('ALTER COLUMN country TYPE VARCHAR(3)', $source);
    }

    // --- Property binding -----------------------------------------------

    public function testFactoryBindsTheRequestedSiteProperty(): void
    {
        $_ENV['SEARCH_CONSOLE_USE_MOCK'] = 'true';

        $connector = ConnectorProviderFactory::searchConsole('sc-domain:aicountly.com');

        $this->assertSame('sc-domain:aicountly.com', $connector->siteProperty());
    }

    public function testForSitePropertyLeavesTheOriginalUntouched(): void
    {
        $original = new GoogleSearchConsoleConnector(
            enabled: true,
            keyPath: '',
            siteProperty: 'sc-domain:one.example',
        );

        $bound = $original->forSiteProperty('sc-domain:two.example');

        $this->assertSame('sc-domain:two.example', $bound->siteProperty());
        $this->assertSame('sc-domain:one.example', $original->siteProperty());
    }

    public function testForSitePropertyIgnoresBlankInput(): void
    {
        $connector = new MockSearchConsoleConnector(siteProperty: 'sc-domain:kept.example');

        $this->assertSame('sc-domain:kept.example', $connector->forSiteProperty('   ')->siteProperty());
    }

    // --- Row normalisation ----------------------------------------------

    /**
     * Every one of these columns is part of uq_search_metric_fact. A NULL in any
     * of them makes the unique index stop matching in Postgres, so re-ingesting
     * a day would append duplicates instead of restating the row.
     */
    public function testAbsentDimensionsBecomeSentinelsNotNulls(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: true, keyPath: '', siteProperty: 'x');

        $row = $connector->normaliseRow(
            ['clicks' => 3, 'impressions' => 90, 'ctr' => 0.033, 'position' => 7.5, 'keys' => ['2026-08-01']],
            ['date'],
            '2026-08-01',
        );

        $this->assertSame('', $row['query']);
        $this->assertSame('UNKNOWN', $row['device']);
        $this->assertSame('ZZZ', $row['country']);
        $this->assertNotNull($row['device']);
    }

    public function testCountryIsKeptAsGooglesThreeLetterCode(): void
    {
        $connector = new GoogleSearchConsoleConnector(enabled: true, keyPath: '', siteProperty: 'x');

        $row = $connector->normaliseRow(
            ['clicks' => 1, 'impressions' => 2, 'keys' => ['2026-08-01', 'https://a/b', 'gst filing', 'MOBILE', 'ind']],
            ['date', 'page', 'query', 'device', 'country'],
            '2026-08-01',
        );

        $this->assertSame('IND', $row['country']);
        $this->assertSame('MOBILE', $row['device']);
    }

    public function testUnexpectedDeviceFallsBackToUnknown(): void
    {
        $this->assertSame('UNKNOWN', SearchMetricFactModel::normaliseDevice('WATCH'));
        $this->assertSame('UNKNOWN', SearchMetricFactModel::normaliseDevice(null));
        $this->assertSame('DESKTOP', SearchMetricFactModel::normaliseDevice('desktop'));
    }

    public function testTwoLetterCountryIsRejectedRatherThanTruncated(): void
    {
        // The column is alpha-3; a stray alpha-2 value is unknown, not "India".
        $this->assertSame('ZZZ', SearchMetricFactModel::normaliseCountry('IN'));
        $this->assertSame('IND', SearchMetricFactModel::normaliseCountry('ind'));
    }

    // --- URL matching ----------------------------------------------------

    /**
     * @dataProvider equivalentUrlProvider
     */
    public function testUrlsThatDifferOnlyInNoiseMatch(string $a, string $b): void
    {
        $this->assertSame(
            ContentIdentitySyncService::normaliseUrl($a),
            ContentIdentitySyncService::normaliseUrl($b),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equivalentUrlProvider(): array
    {
        return [
            'trailing slash' => ['https://aicountly.com/blog/gst', 'https://aicountly.com/blog/gst/'],
            'scheme'         => ['http://aicountly.com/blog/gst', 'https://aicountly.com/blog/gst'],
            'www prefix'     => ['https://www.aicountly.com/blog/gst', 'https://aicountly.com/blog/gst'],
            'host case'      => ['https://AICOUNTLY.com/blog/gst', 'https://aicountly.com/blog/gst'],
            'query string'   => ['https://aicountly.com/blog/gst?utm_source=x', 'https://aicountly.com/blog/gst'],
            'fragment'       => ['https://aicountly.com/blog/gst#intro', 'https://aicountly.com/blog/gst'],
        ];
    }

    public function testDifferentPathsDoNotMatch(): void
    {
        $this->assertNotSame(
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blog/gst'),
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blog/tds'),
        );
    }

    public function testPathCaseIsSignificant(): void
    {
        // Most servers serve these as different resources; collapsing them would
        // silently attribute one post's traffic to another.
        $this->assertNotSame(
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blog/GST'),
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blog/gst'),
        );
    }

    public function testBlankUrlNormalisesToEmptyRatherThanMatchingEverything(): void
    {
        $this->assertSame('', ContentIdentitySyncService::normaliseUrl('   '));
    }

    // --- Slug matching across the /blog/ → /blogs/ redirect ---------------

    /**
     * The public site 301s /blog/{slug} to /blogs/{slug} and Search Console
     * reports the destination, so an identity built from CanonicalUrlPolicy
     * never matches on an exact comparison. The slug survives the redirect.
     */
    public function testSlugSurvivesThePathPrefixRedirect(): void
    {
        $stored   = 'https://www.aicountly.com/blog/gst-return-due-dates';
        $reported = 'https://aicountly.com/blogs/gst-return-due-dates';

        $this->assertNotSame(
            ContentIdentitySyncService::normaliseUrl($stored),
            ContentIdentitySyncService::normaliseUrl($reported),
            'These are deliberately unequal — that is why the exact match failed.',
        );
        $this->assertSame(
            ContentIdentitySyncService::slugOf($stored),
            ContentIdentitySyncService::slugOf($reported),
        );
    }

    public function testSlugIgnoresTrailingSlashAndQueryString(): void
    {
        $this->assertSame(
            'gst-returns',
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/gst-returns/?utm_source=x'),
        );
    }

    /**
     * A bare host must never produce a slug — otherwise the homepage's search
     * traffic would be attributed to whichever post sorted first.
     */
    public function testBareHostHasNoSlug(): void
    {
        $this->assertSame('', ContentIdentitySyncService::slugOf('https://aicountly.com'));
        $this->assertSame('', ContentIdentitySyncService::slugOf('https://aicountly.com/'));
        $this->assertSame('', ContentIdentitySyncService::slugOf(''));
    }

    public function testSlugIsCaseInsensitive(): void
    {
        $this->assertSame(
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/GST-Returns'),
            ContentIdentitySyncService::slugOf('https://aicountly.com/blog/gst-returns'),
        );
    }

    public function testDistinctPostsKeepDistinctSlugs(): void
    {
        $this->assertNotSame(
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/gst-returns'),
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/tds-returns'),
        );
    }
}
