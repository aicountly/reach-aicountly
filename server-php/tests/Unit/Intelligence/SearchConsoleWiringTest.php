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
            'trailing slash' => ['https://aicountly.com/blogs/gst', 'https://aicountly.com/blogs/gst/'],
            'scheme'         => ['http://aicountly.com/blogs/gst', 'https://aicountly.com/blogs/gst'],
            'www prefix'     => ['https://www.aicountly.com/blogs/gst', 'https://aicountly.com/blogs/gst'],
            'host case'      => ['https://AICOUNTLY.com/blogs/gst', 'https://aicountly.com/blogs/gst'],
            'query string'   => ['https://aicountly.com/blogs/gst?utm_source=x', 'https://aicountly.com/blogs/gst'],
            'fragment'       => ['https://aicountly.com/blogs/gst#intro', 'https://aicountly.com/blogs/gst'],
        ];
    }

    public function testDifferentPathsDoNotMatch(): void
    {
        $this->assertNotSame(
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blogs/gst'),
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blogs/tds'),
        );
    }

    public function testPathCaseIsSignificant(): void
    {
        // Most servers serve these as different resources; collapsing them would
        // silently attribute one post's traffic to another.
        $this->assertNotSame(
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blogs/GST'),
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/blogs/gst'),
        );
    }

    public function testBlankUrlNormalisesToEmptyRatherThanMatchingEverything(): void
    {
        $this->assertSame('', ContentIdentitySyncService::normaliseUrl('   '));
    }

    // --- Slug helpers (diagnostic only) ----------------------------------

    /**
     * slugOf backs the `unmapped` diagnostic, which reports whether a blog with
     * the same final path segment exists. It is deliberately NOT used to
     * attribute traffic: a Search Console property covers the whole site, so
     * /guides/hrms/dashboard and a post slugged "dashboard" would collide and
     * a marketing page's clicks would be credited to a blog post.
     */
    public function testSlugIgnoresTrailingSlashAndQueryString(): void
    {
        $this->assertSame(
            'gst-returns',
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/gst-returns/?utm_source=x'),
        );
    }

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
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/gst-returns'),
        );
    }

    public function testDistinctPostsKeepDistinctSlugs(): void
    {
        $this->assertNotSame(
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/gst-returns'),
            ContentIdentitySyncService::slugOf('https://aicountly.com/blogs/tds-returns'),
        );
    }

    /**
     * Attribution is exact-match only. A guide and a post that happen to share
     * a final segment are different pages, and the fact table must not merge
     * them.
     */
    public function testGuideAndBlogSharingASegmentAreNotTheSameUrl(): void
    {
        $this->assertNotSame(
            ContentIdentitySyncService::normaliseUrl('https://aicountly.com/guides/hrms/dashboard'),
            ContentIdentitySyncService::normaliseUrl('https://www.aicountly.com/blogs/dashboard'),
        );
    }

    /**
     * After a slug repair the item's slug is corrected immediately but the post
     * is only re-published later, so the live URL and the slug-derived URL
     * disagree and Google reports whichever it last crawled. Both must resolve
     * to the same post — the host and www difference between the two sources
     * must not stop that.
     */
    public function testPreAndPostRepairUrlsForOnePostAreDistinctButBothResolvable(): void
    {
        $published  = 'https://www.aicountly.com/blogs/nput-ax-redit-xplained-for-mall-usinesses';
        $fromSlug   = 'https://www.aicountly.com/blogs/gst-input-tax-credit-explained-for-small-businesses';
        $asReported = 'https://aicountly.com/blogs/gst-input-tax-credit-explained-for-small-businesses';

        // Different URLs — indexing only one of them loses the other's traffic.
        $this->assertNotSame(
            ContentIdentitySyncService::normaliseUrl($published),
            ContentIdentitySyncService::normaliseUrl($fromSlug),
        );

        // Google drops the www; the index must still recognise the slug URL.
        $this->assertSame(
            ContentIdentitySyncService::normaliseUrl($fromSlug),
            ContentIdentitySyncService::normaliseUrl($asReported),
        );
    }
}
