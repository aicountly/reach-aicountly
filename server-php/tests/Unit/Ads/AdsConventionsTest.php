<?php

declare(strict_types=1);

namespace Tests\Unit\Ads;

use App\Libraries\Ads\AdsConventions;
use App\Libraries\Ads\AdsCsvImporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * UTM conventions, the link builder, campaign validation and the CSV parser
 * (pure, no database). The same link examples are pinned on the web side in
 * web/src/pages/social/ads/__tests__/adsUtm.test.js.
 *
 * @internal
 */
final class AdsConventionsTest extends TestCase
{
    public function testEachPlatformHasReachsUtmSet(): void
    {
        $set = array_map(static fn (array $p): string => $p['utm_source'] . '/' . $p['utm_medium'], AdsConventions::PLATFORMS);
        $this->assertSame([
            'google_search' => 'google/cpc',
            'youtube'       => 'youtube/video',
            'meta'          => 'meta/paid_social',
            'linkedin'      => 'linkedin/paid_social',
        ], $set);
        $this->assertSame('soft-launch-2026-10', AdsConventions::DEFAULT_CAMPAIGN);
    }

    public function testTheLaunchPlanIsTheOwnersSplitAndOnlyAPlan(): void
    {
        $this->assertSame(['google_search' => 600, 'youtube' => 300, 'meta' => 300, 'linkedin' => 800], AdsConventions::LAUNCH_PLAN);
        $this->assertSame(2000, array_sum(AdsConventions::LAUNCH_PLAN));
        $meta = AdsConventions::meta();
        $this->assertStringContainsString('not spend', $meta['launch_plan']['note']);
        $this->assertStringContainsString('not connected', $meta['connection']);
    }

    /** @return iterable<string, array{string, string, array, string}> */
    public static function links(): iterable
    {
        $landing = 'https://aicountly.com/free-accounting-software';
        yield 'google search' => [$landing, 'google_search', ['utm_content' => 'gs-rsa-01'],
            'https://aicountly.com/free-accounting-software?utm_source=google&utm_medium=cpc&utm_campaign=soft-launch-2026-10&utm_content=gs-rsa-01'];
        yield 'youtube' => [$landing, 'youtube', ['utm_content' => 'yt-reel-01'],
            'https://aicountly.com/free-accounting-software?utm_source=youtube&utm_medium=video&utm_campaign=soft-launch-2026-10&utm_content=yt-reel-01'];
        yield 'meta' => [$landing, 'meta', ['utm_content' => 'fb-reel-01'],
            'https://aicountly.com/free-accounting-software?utm_source=meta&utm_medium=paid_social&utm_campaign=soft-launch-2026-10&utm_content=fb-reel-01'];
        yield 'linkedin, own campaign' => [$landing, 'linkedin', ['utm_campaign' => 'ca-firms-2026-11', 'utm_content' => 'li-single-01'],
            'https://aicountly.com/free-accounting-software?utm_source=linkedin&utm_medium=paid_social&utm_campaign=ca-firms-2026-11&utm_content=li-single-01'];
        yield 'keeps own query, drops old tracking, keeps fragment' => ['https://aicountly.com/pricing?plan=free&utm_source=old&gclid=X#faqs', 'meta', [],
            'https://aicountly.com/pricing?plan=free&utm_source=meta&utm_medium=paid_social&utm_campaign=soft-launch-2026-10#faqs'];
    }

    /** @dataProvider links */
    public function testBuildLink(string $landing, string $platform, array $utm, string $expected): void
    {
        $this->assertSame($expected, AdsConventions::buildLink($landing, $platform, $utm));
    }

    public function testBuildLinkRefusesWhatBreaksTheConventions(): void
    {
        foreach ([
            ['http://aicountly.com/x', 'meta', []],
            ['https://example.com/x', 'meta', []],
            ['https://aicountly.com.evil.example/x', 'meta', []],
            ['https://aicountly.com/x', 'tiktok', []],
            ['https://aicountly.com/x', 'meta', ['utm_content' => 'Reel 01']],
            ['https://aicountly.com/x', 'meta', ['utm_campaign' => 'Soft Launch']],
        ] as [$landing, $platform, $utm]) {
            try {
                AdsConventions::buildLink($landing, $platform, $utm);
                $this->fail("accepted $landing / $platform / " . json_encode($utm));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull(AdsConventions::landingProblem('https://www.aicountly.com/free-accounting-software'));
    }

    public function testNormalizeCampaignFillsTheConventionsAndValidates(): void
    {
        $row = AdsConventions::normalizeCampaign([
            'platform' => 'meta', 'name' => ' Soft launch FB+IG ', 'daily_budget_inr' => '300',
            'start_date' => '2026-10-18', 'landing_url' => AdsConventions::DEFAULT_LANDING,
            'creatives' => [['id' => 'FB-Reel-01', 'label' => 'Reel 1'], 'fb-carousel-01'], 'ignored' => 'x',
        ]);
        $this->assertSame('meta', $row['utm_source']);
        $this->assertSame('paid_social', $row['utm_medium']);
        $this->assertSame('soft-launch-2026-10', $row['utm_campaign']);
        $this->assertSame('Soft launch FB+IG', $row['name']);
        $this->assertSame('Soft launch FB+IG', $row['external_ref'], 'the ads-manager name defaults to the name');
        $this->assertSame('300.00', $row['daily_budget_inr']);
        $this->assertSame('planned', $row['status']);
        $this->assertSame(['fb-reel-01', 'fb-carousel-01'], array_column($row['creatives'], 'id'));
        $this->assertArrayNotHasKey('ignored', $row);

        $links = AdsConventions::trackingLinks($row + ['id' => 1]);
        $this->assertSame(['fb-reel-01', 'fb-carousel-01'], array_column($links, 'creative_id'));
        $this->assertStringEndsWith('utm_content=fb-carousel-01', $links[1]['url']);

        foreach ([
            ['platform' => 'tiktok'],
            ['name' => ''],
            ['daily_budget_inr' => -1],
            ['start_date' => '18-10-2026'],
            ['end_date' => '2026-10-01'],
            ['landing_url' => 'https://example.com'],
            ['creatives' => [['id' => 'a'], ['id' => 'a']]],
            ['status' => 'running'],
            ['objective' => 'sales'],
        ] as $bad) {
            try {
                AdsConventions::normalizeCampaign($bad + ['platform' => 'meta', 'name' => 'X', 'start_date' => '2026-10-18', 'landing_url' => AdsConventions::DEFAULT_LANDING]);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGoogleAdsExportWithTitleRowsAndTotals(): void
    {
        $csv = "Campaign report\n\"October 18, 2026 - October 19, 2026\"\n"
            . "Day,Campaign,Campaign status,Currency code,Cost,Impr.,Clicks,Conversions\n"
            . "2026-10-18,Soft launch - Search,Enabled,INR,\"598.40\",\"1,234\",41,3.00\n"
            . "\"Oct 19, 2026\",Soft launch - Search,Enabled,INR,612.10,1100,38,2\n"
            . "2026-10-19,YT reel,Enabled,INR,--,--,--,--\n"
            . "Total: Campaigns,,,,1210.50,2334,79,5\n";
        $p = AdsCsvImporter::parse($csv, 'auto');
        $this->assertNull($p['error']);
        $this->assertSame('google_ads', $p['format']);
        $this->assertCount(2, $p['rows']);
        $this->assertSame(['line' => 4, 'date' => '2026-10-18', 'campaign' => 'Soft launch - Search', 'spend' => 598.4, 'impressions' => 1234, 'clicks' => 41, 'conversions' => 3.0], $p['rows'][0]);
        $this->assertSame('2026-10-19', $p['rows'][1]['date']);
        $this->assertSame(2, $p['skipped'], 'a row with no figures and the totals row are skipped, not stored as zero');
    }

    public function testMetaExportNeedsADailyBreakdownAndInr(): void
    {
        $csv = "\xEF\xBB\xBFReporting starts,Reporting ends,Campaign name,Amount spent (INR),Impressions,Link clicks,Results,Result indicator\n"
            . "2026-10-18,2026-10-18,Soft launch FB+IG,301.25,8800,95,4,actions:offsite_conversion.fb_pixel_lead\n"
            . "2026-10-18,2026-10-24,Soft launch FB+IG,2100,61000,600,22,x\n";
        $p = AdsCsvImporter::parse($csv, 'auto');
        $this->assertSame('meta', $p['format']);
        $this->assertCount(1, $p['rows']);
        $this->assertSame(301.25, $p['rows'][0]['spend']);
        $this->assertSame(4.0, $p['rows'][0]['conversions']);
        $this->assertStringContainsString('more than one day', $p['rejected'][0]['reason']);

        $usd = AdsCsvImporter::parse("Day,Campaign name,Amount spent (USD),Impressions\n2026-10-18,X,3.5,100\n", 'meta');
        $this->assertStringContainsString('INR only', (string) $usd['error']);
    }

    public function testLinkedInExportUsesUsDates(): void
    {
        $csv = "Campaign Performance Report\nReport Start: 10/18/2026, Report End: 10/19/2026\n\n"
            . "Start Date (in UTC),Campaign Name,Campaign ID,Currency,Total Spent,Impressions,Clicks,Conversions\n"
            . "10/18/2026,Soft launch LinkedIn,123,INR,\"₹799.90\",2100,17,1\n"
            . "10/19/2026,Soft launch LinkedIn,123,USD,9.50,300,2,0\n";
        $p = AdsCsvImporter::parse($csv, 'linkedin');
        $this->assertNull($p['error']);
        $this->assertSame('2026-10-18', $p['rows'][0]['date']);
        $this->assertSame(799.9, $p['rows'][0]['spend']);
        $this->assertStringContainsString('INR only', $p['rejected'][0]['reason']);
        $this->assertSame('linkedin', AdsCsvImporter::parse($csv, 'auto')['format']);
    }

    public function testTheSignupsReportFromMyAicountly(): void
    {
        $csv = "date,utm_source,utm_medium,utm_campaign,utm_content,signups,with_click_id\n"
            . "2026-10-18,google,cpc,soft-launch-2026-10,gs-rsa-01,3,2\n"
            . "2026-10-18,,,,,5,0\n"
            . "2026-10-18,meta,paid_social,soft-launch-2026-10,'=x,1,1\n";
        $p = AdsCsvImporter::parse($csv, 'auto');
        $this->assertSame('aicountly_signups', $p['format']);
        $this->assertCount(3, $p['rows']);
        $this->assertSame(3, $p['rows'][0]['signups']);
        $this->assertSame('=x', $p['rows'][2]['utm_content'], 'the report\'s formula guard is removed');
    }

    public function testAmbiguousOrBrokenValuesAreRejectedNotGuessed(): void
    {
        $this->assertNull(AdsCsvImporter::parseDate('05/06/2026', 'google_ads'), 'day/month order is not guessed');
        $this->assertSame('2026-10-18', AdsCsvImporter::parseDate('18/10/2026', 'google_ads'));
        $this->assertSame('2026-10-18', AdsCsvImporter::parseDate('Oct 18, 2026', 'google_ads'));
        $this->assertNull(AdsCsvImporter::parseDate('2026-02-30', 'meta'));

        $p = AdsCsvImporter::parse("Day,Campaign,Cost,Impr.,Clicks\n2026-10-18,X,abc,1,1\n2026-10-18,X,-5,1,1\n2026-10-18,X,1,1.5,1\n", 'google_ads');
        $this->assertCount(0, $p['rows']);
        $this->assertCount(3, $p['rejected']);

        $this->assertNotNull(AdsCsvImporter::parse('', 'auto')['error']);
        $this->assertNotNull(AdsCsvImporter::parse("a,b\n1,2\n", 'auto')['error']);
        $this->assertNotNull(AdsCsvImporter::parse("Day,Campaign,Cost\n", 'tiktok')['error']);
    }

    public function testTabSeparatedUtf16ExportsAreRead(): void
    {
        $tsv = "Day\tCampaign\tCost\tImpr.\tClicks\tConversions\n2026-10-18\tSoft launch - Search\t100.00\t500\t10\t1\n";
        $utf16 = "\xFF\xFE" . mb_convert_encoding($tsv, 'UTF-16LE', 'UTF-8');
        $p = AdsCsvImporter::parse($utf16, 'google_ads');
        $this->assertNull($p['error']);
        $this->assertSame(100.0, $p['rows'][0]['spend']);
    }
}
