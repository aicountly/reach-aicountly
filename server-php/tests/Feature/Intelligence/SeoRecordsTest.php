<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Controllers\Api\V1\Intelligence\SeoCommandCentreController;
use App\Controllers\Api\V1\Intelligence\SitemapController;
use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\Seo\SeoRecordsService;
use App\Libraries\Intelligence\Seo\SeoSnapshotService;
use App\Libraries\Intelligence\SitemapSnapshotService;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\SitemapSnapshotModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\ControllerTestTrait;
use Tests\Support\DatabaseTestCase;

/**
 * The SEO Command Centre counts (backlink snapshot, sitemap snapshot,
 * IndexNow statuses, blog indexing statuses) each open a list built on the
 * same predicate, down to the single record.
 *
 * @covers \App\Libraries\Intelligence\Seo\SeoRecordsService
 * @covers \App\Libraries\Intelligence\SitemapSnapshotService
 * @covers \App\Controllers\Api\V1\Intelligence\SeoCommandCentreController
 * @covers \App\Controllers\Api\V1\Intelligence\SitemapController
 */
final class SeoRecordsTest extends DatabaseTestCase
{
    use ControllerTestTrait;

    protected $migrateOnce = true;

    /** @var list<int> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        $db->query('TRUNCATE reach_indexnow_attempts, reach_indexnow_submissions, reach_sitemap_entries, reach_sitemap_snapshots, reach_backlink_snapshots, reach_content_internal_links, reach_blog_publication_profiles RESTART IDENTITY CASCADE');
        $db->query('DELETE FROM reach_content_identities');

        $this->items = [];
        foreach ([['Live GST post', 'live', 'indexed'], ['Published e-invoice', 'published', 'sitemap_included'], ['Draft idea', 'draft', 'not_submitted']] as [$title, $state, $indexing]) {
            $db->table('reach_content_items')->insert([
                'content_type' => 'blog', 'title' => $title, 'slug' => 'seo-rec-' . bin2hex(random_bytes(4)), 'workflow_status' => $state,
            ]);
            $id            = (int) $db->insertID();
            $this->items[] = $id;
            $db->table('reach_blog_publication_profiles')->insert(['content_item_id' => $id, 'indexing_status' => $indexing]);
        }
        $db->table('reach_content_internal_links')->insert(['source_content_item_id' => $this->items[0], 'target_content_item_id' => $this->items[1], 'target_type' => 'internal', 'anchor_text' => 'e-invoice guide', 'status' => 'active']);
        $db->table('reach_content_internal_links')->insert(['source_content_item_id' => $this->items[1], 'target_type' => 'external', 'target_public_url' => 'https://gst.gov.in', 'status' => 'broken']);
    }

    public function testBacklinkSnapshotOpensTheRecordsBehindItsCounts(): void
    {
        $db = \Config\Database::connect();
        (new SeoSnapshotService())->captureBacklinkSnapshot();
        $snap = $db->table('reach_backlink_snapshots')->get()->getRowArray();
        $this->assertSame(2, (int) $snap['internal_links']);
        $this->assertSame(2, (int) $snap['published_urls']);
        // A provider that does return lists: they become rows.
        $db->table('reach_backlink_snapshots')->where('id', $snap['id'])->update([
            'details_json' => json_encode(['note' => 'test', 'referring_domains' => [['domain' => 'example.org', 'backlinks' => 3]]]),
        ]);

        $svc = new SeoRecordsService();
        $d   = $svc->backlinkSnapshot((int) $snap['id']);
        $this->assertSame('test', $d['detail_facts']['note']);
        $this->assertSame('referring_domains', $d['detail_lists'][0]['key']);
        $this->assertSame('example.org', $d['detail_lists'][0]['rows'][0]['domain']);
        $this->assertSame((int) $snap['internal_links'], $d['records']['total']);
        $this->assertSame(['internal_links' => 2, 'published_urls' => 2], $d['current_count']);

        $pub = $svc->backlinkSnapshot((int) $snap['id'], ['kind' => 'published_urls']);
        $this->assertSame((int) $snap['published_urls'], $pub['records']['total']);
        $this->assertNotContains('Draft idea', array_column($pub['records']['rows'], 'title'));

        $broken = $svc->backlinkSnapshot((int) $snap['id'], ['status' => 'broken']);
        $this->assertSame(1, $broken['records']['total']);
        $this->assertSame('https://gst.gov.in', $broken['records']['rows'][0]['target_public_url']);
        $this->assertNull($svc->backlinkSnapshot(99999));

        $json = json_decode((string) $this->withRequest($this->get(['kind' => 'published_urls']))
            ->controller(SeoCommandCentreController::class)->execute('backlinkSnapshot', (int) $snap['id'])->getJSON(), true);
        $this->assertSame(2, $json['data']['records']['total']);
    }

    public function testBlogIndexingCountsOpenThePostsInThatState(): void
    {
        $svc = new SeoRecordsService();
        $all = $svc->blogPosts([]);
        $this->assertSame(['indexed' => 1, 'not_submitted' => 1, 'sitemap_included' => 1], (function (array $c) {
            ksort($c);
            return $c;
        })($all['counts']));
        foreach ($all['counts'] as $state => $count) {
            $rows = $svc->blogPosts(['indexing_status' => $state]);
            $this->assertSame($count, $rows['total'], $state);
        }
        $this->assertSame($this->items[0], (int) $svc->blogPosts(['indexing_status' => 'indexed'])['rows'][0]['content_item_id']);

        $json = json_decode((string) $this->withRequest($this->get(['status' => 'not_submitted']))
            ->controller(SeoCommandCentreController::class)->execute('indexing')->getJSON(), true);
        $this->assertSame('Draft idea', $json['data']['posts'][0]['title']);
        $this->assertSame(1, $json['data']['meta']['total']);
    }

    public function testIndexNowCountsListsAndAttempts(): void
    {
        $db = \Config\Database::connect();
        foreach ([['https://aicountly.com/blog/a', 'submitted'], ['https://aicountly.com/blog/b', 'failed'], ['https://aicountly.com/pricing', 'retrying'], ['https://aicountly.com/blog/c', 'pending']] as $i => [$url, $status]) {
            $db->table('reach_indexnow_submissions')->insert([
                'tenant_id' => 1, 'url' => $url, 'provider_endpoint' => 'https://api.indexnow.org/indexnow', 'idempotency_key' => 'k' . $i, 'status' => $status, 'attempt_count' => 1,
            ]);
            $sid = (int) $db->insertID();
            $db->table('reach_indexnow_attempts')->insert([
                'submission_id' => $sid, 'attempt_number' => 1, 'http_status' => $status === 'submitted' ? 200 : 429,
                'error_message' => $status === 'submitted' ? null : 'Too many requests', 'succeeded' => $status === 'submitted',
            ]);
        }

        $svc = new SeoRecordsService();
        $all = $svc->indexnowSubmissions([]);
        $this->assertSame(4, $all['counts']['total']);
        $this->assertSame(1, $all['counts']['failed']);
        $this->assertSame(2, $svc->indexnowSubmissions(['status' => 'retrying,pending'])['total']);
        $this->assertSame(3, $svc->indexnowSubmissions(['url' => '/blog/'])['total']);
        $this->assertSame(3, $svc->indexnowSubmissions(['url' => '/blog/'])['counts']['total'], 'counts follow the url filter');
        $failed = $svc->indexnowSubmissions(['status' => 'failed'])['rows'][0];
        $this->assertSame('Too many requests', $failed['last_error']);

        $one = $svc->indexnowSubmission((int) $failed['id']);
        $this->assertSame(429, (int) $one['attempts'][0]['http_status']);
        $this->assertNull($svc->indexnowSubmission(99999));

        $json = json_decode((string) $this->withRequest($this->get(['status' => 'submitted', 'limit' => 10]))
            ->controller(SeoCommandCentreController::class)->execute('indexnowSubmissions')->getJSON(), true);
        $this->assertCount(1, $json['data']['submissions']);
        $this->assertSame(4, $json['data']['counts']['total']);
    }

    public function testSitemapCountsOpenTheirEntries(): void
    {
        $model = new ContentIdentityModel();
        foreach ([
            ['published', true, 'https://aicountly.com/blog/live', $this->items[0]],
            ['noindex', true, 'https://aicountly.com/blog/hidden', $this->items[1]],
            ['withdrawn', true, 'https://aicountly.com/blog/gone', $this->items[2]],
            ['published', false, 'https://aicountly.com/blog/ineligible', 900001],
            ['published', true, null, 900002], // counted, but no URL → no entry row
        ] as [$status, $eligible, $url, $source]) {
            $model->insert(['tenant_id' => 1, 'content_type' => 'blog', 'source_id' => $source, 'canonical_url' => $url, 'publication_status' => $status, 'analytics_eligible' => $eligible, 'privacy_class' => 'public']);
        }
        $svc  = new SitemapSnapshotService(new ContentIdentityModel(), new SitemapSnapshotModel(), new AuditLogger());
        $snap = $svc->generateSnapshot(1, 'manual');
        $sid  = (int) $snap['id'];

        $included = $svc->listEntries($sid, ['state' => 'included']);
        // The URL-less identity is counted as included but has no entry.
        $this->assertSame((int) $snap['included_entries'] - 1, $included['total']);
        $this->assertSame(1, $included['unlisted']);
        $this->assertSame($this->items[0], $included['rows'][0]['content_item_id']);
        $this->assertSame((int) $snap['excluded_noindex'], $svc->listEntries($sid, ['reason' => 'noindex'])['total']);
        $this->assertSame((int) $snap['excluded_withdrawn'], $svc->listEntries($sid, ['reason' => 'withdrawn'])['total']);
        $this->assertSame((int) $snap['excluded_other'], $svc->listEntries($sid, ['reason' => 'other'])['total']);
        $this->assertSame((int) $snap['total_entries'], $svc->listEntries($sid)['total'] + $included['unlisted']);
        $this->assertSame(1, $svc->listEntries($sid, ['q' => 'hidden'])['total']);

        $json = json_decode((string) $this->withRequest($this->get(['reason' => 'withdrawn']))
            ->controller(SitemapController::class)->execute('entries', $sid)->getJSON(), true);
        $this->assertSame('https://aicountly.com/blog/gone', $json['data'][0]['url']);
        $this->assertSame(1, $json['meta']['facets']['withdrawn']);

        // Legacy call (no filters) keeps returning included entries only.
        $legacy = json_decode((string) $this->withRequest($this->get([]))
            ->controller(SitemapController::class)->execute('entries', $sid)->getJSON(), true);
        $this->assertSame(1, $legacy['count']);
    }

    /** @param array<string, mixed> $get */
    private function get(array $get): IncomingRequest
    {
        $config  = new \Config\App();
        $request = new IncomingRequest($config, new SiteURI($config), null, new UserAgent());
        $request->setGlobal('get', $get);

        return $request;
    }
}
