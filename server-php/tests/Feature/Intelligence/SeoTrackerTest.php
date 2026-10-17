<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Seo\DataForSeoSerpClient;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;
use Tests\Unit\Intelligence\DataForSeoSerpClientTest;

/**
 * SEO Tracker against a fake DataForSEO transport: seeded keywords and
 * competitors, cost cap, per-day idempotency, early stop on auth failure,
 * and "not configured" writing nothing.
 */
final class SeoTrackerTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        ReachConfig::flush();
        require_once ROOTPATH . 'tests/Unit/Intelligence/DataForSeoSerpClientTest.php';
        $this->calls = [];
    }

    private function client(?callable $respond = null): DataForSeoSerpClient
    {
        $respond ??= static fn (string $keyword): array => ['status' => 200, 'body' => DataForSeoSerpClientTest::okBody(DataForSeoSerpClientTest::sampleItems(), 0.002)];

        return new DataForSeoSerpClient('login', 'password', function (string $url, string $body) use ($respond): array {
            $keyword       = json_decode($body, true)[0]['keyword'];
            $this->calls[] = $keyword;

            return $respond($keyword);
        });
    }

    public function testMigrationSeedsCompetitorsAndLaunchKeywords(): void
    {
        $db = Database::connect();
        $domains = array_column($db->query("SELECT website_domain FROM reach_competitors WHERE tenant_id = 1 AND category = 'accounting_saas'")->getResultArray(), 'website_domain');
        foreach (['tallysolutions.com', 'zoho.com', 'vyaparapp.in', 'busy.in', 'margcompusoft.com', 'mybillbook.in', 'cleartax.in', 'quickbooks.intuit.com', 'khatabook.com', 'getswipe.in'] as $d) {
            $this->assertContains($d, $domains);
        }
        $keywords = (int) $db->table('reach_seo_tracked_keywords')->where('enabled', true)->countAllResults();
        $this->assertGreaterThanOrEqual(30, $keywords);
        $this->assertSame(1, (int) $db->table('reach_seo_tracked_keywords')->where('keyword', 'aicountly')->countAllResults());
    }

    public function testRunStoresRanksRespectsCapAndIsIdempotentPerDay(): void
    {
        $service = new SeoTrackerService(null, $this->client());
        $result  = $service->runDaily('2026-10-17', 3);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(3, $result['checked']);
        $this->assertCount(3, $this->calls, 'Cost cap: exactly `limit` paid calls.');
        $this->assertEqualsWithDelta(0.006, $result['cost'], 0.00001);

        $db    = Database::connect();
        $check = $db->table('reach_seo_serp_checks')->where('status', 'ok')->get()->getRowArray();
        $this->assertSame(7, (int) $check['our_rank']);
        $this->assertSame('https://www.aicountly.com/billing', $check['our_url']);
        $zoho = $db->query("SELECT r.rank_absolute FROM reach_seo_serp_rankings r JOIN reach_competitors c ON c.id = r.competitor_id WHERE c.name = 'Zoho Books' AND r.keyword_id = ?", [$check['keyword_id']])->getRowArray();
        $this->assertSame(4, (int) $zoho['rank_absolute']);

        // Same day again with the same cap: nothing left to buy.
        $again = $service->runDaily('2026-10-17', 3);
        $this->assertSame(0, $again['checked']);
        $this->assertCount(3, $this->calls);

        // A larger cap only buys the keywords not yet checked today.
        $service->runDaily('2026-10-17', 5);
        $this->assertCount(5, $this->calls);
        $this->assertSame(count($this->calls), count(array_unique($this->calls)));

        $table = $service->keywordTable('2026-10-17');
        $this->assertSame(5, $table['summary']['checked']);
        $this->assertSame(5, $table['summary']['competitor_wins'], 'Vyapar at #2 beats our #7.');
        $row = current(array_filter($table['keywords'], static fn ($k) => $k['check_status'] === 'ok'));
        $this->assertSame('Vyapar', $row['winner']['name']);
        $share = array_column($table['top10_share'], null, 'name');
        $this->assertSame(1.0, $share['aicountly']['share']);
        $this->assertSame(0, $share['Tally']['top10']);
    }

    public function testChangeIsMeasuredAgainstACheckAtLeastSevenDaysOlder(): void
    {
        // One enabled keyword, so both runs check the same one (otherwise the
        // rotation picks the least recently checked keyword).
        $db = Database::connect();
        $db->query("UPDATE reach_seo_tracked_keywords SET enabled = (keyword = 'gst billing software')");

        $items = DataForSeoSerpClientTest::sampleItems();
        $old   = new SeoTrackerService(null, $this->client(static function () use ($items): array {
            $items[5]['rank_absolute'] = 15; // we were #15
            unset($items[6]);

            return ['status' => 200, 'body' => DataForSeoSerpClientTest::okBody(array_values($items))];
        }));
        $old->runDaily('2026-10-09', 1);

        $new = new SeoTrackerService(null, $this->client());
        $new->runDaily('2026-10-17', 1);

        $table = $new->keywordTable('2026-10-17');
        $row   = current(array_filter($table['keywords'], static fn ($k) => $k['check_status'] === 'ok'));
        $this->assertSame(15, $row['previous_rank']);
        $this->assertSame(7, $row['our_rank']);
        $this->assertSame(8, $row['change'], 'Positive = moved up.');
        $this->assertSame(1, $table['summary']['up']);
    }

    public function testAuthFailureStopsTheRunAndIsRecordedAsFailed(): void
    {
        $service = new SeoTrackerService(null, $this->client(static fn (): array => ['status' => 401, 'body' => '{"status_code":40100,"status_message":"You are not authorized"}']));
        $result  = $service->runDaily('2026-10-17', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('auth', $result['stopped']);
        $this->assertCount(1, $this->calls, 'Stop after the first auth failure instead of burning 10 calls.');
        $db = Database::connect();
        $this->assertSame(1, (int) $db->table('reach_seo_serp_checks')->where('status', 'failed')->countAllResults());
        $this->assertSame(0, (int) $db->table('reach_seo_serp_rankings')->countAllResults(), 'A failure is never stored as "not ranking".');
    }

    public function testWithoutCredentialsNothingIsWritten(): void
    {
        $service = new SeoTrackerService(null, new DataForSeoSerpClient(null, null, function (): array {
            $this->calls[] = 'x';

            return ['status' => 200, 'body' => '{}'];
        }));

        $this->assertSame('not_configured', $service->status()['status']);
        $result = $service->runDaily('2026-10-17');
        $this->assertSame('not_configured', $result['status']);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, (int) Database::connect()->table('reach_seo_serp_checks')->countAllResults());
        $this->assertNull($service->keywordTable()['date']);
    }
}
