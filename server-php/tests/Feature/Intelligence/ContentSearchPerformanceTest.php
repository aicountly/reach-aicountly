<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Libraries\Database\SchemaGuard;
use Tests\Support\ApiTestCase;

/**
 * Intelligence → Content Performance: v1/intelligence/content/metrics groups
 * site-wide Search Console rows by content identity (URL index), and
 * v1/intelligence/content/metrics/{id} returns that identity's rows per
 * day × page — which add up to the list row.
 *
 * @internal
 */
final class ContentSearchPerformanceTest extends ApiTestCase
{
    private int $blogIdentity;
    private int $plainIdentity;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        $db = \Config\Database::connect();

        $db->table('reach_analytics_connections')->insert([
            'tenant_id' => 1, 'provider' => 'gsc', 'display_name' => 'GSC', 'site_property' => 'sc-domain:example.com', 'enabled' => true,
        ]);
        $conn = (int) $db->insertID();

        $db->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => 'GST explained', 'slug' => 'gst-a', 'workflow_status' => 'live',
        ]);
        $this->itemId = (int) $db->insertID();
        $db->table('reach_content_identities')->insert(['tenant_id' => 1, 'content_type' => 'blog', 'source_id' => $this->itemId, 'canonical_url' => 'https://example.com/blogs/gst-a']);
        $this->blogIdentity = (int) $db->insertID();
        $db->table('reach_content_identities')->insert(['tenant_id' => 1, 'content_type' => 'page', 'source_id' => 5, 'canonical_url' => 'https://example.com/pricing']);
        $this->plainIdentity = (int) $db->insertID();

        foreach ([
            // www + trailing slash are normalised onto the identity's URL.
            ['2026-10-20', 'https://www.example.com/blogs/gst-a/', 'gst', 10, 100, 3.0],
            ['2026-10-20', 'https://www.example.com/blogs/gst-a/', 'gst rate', 2, 50, 6.0],
            ['2026-10-21', 'https://www.example.com/blogs/gst-a/', 'gst', 5, 40, 4.0],
            ['2026-10-21', 'https://example.com/pricing', 'pricing', 3, 30, 2.0],
            ['2026-10-21', 'https://example.com/careers', 'jobs', 7, 70, 1.0],
            // Outside the 28-day window ending on the freshest day.
            ['2026-08-01', 'https://example.com/pricing', 'pricing', 99, 990, 1.0],
        ] as [$date, $page, $query, $clicks, $impressions, $pos]) {
            $db->table('reach_search_site_facts')->insert([
                'connection_id' => $conn, 'metric_date' => $date, 'page_url' => $page, 'query' => $query,
                'clicks' => $clicks, 'impressions' => $impressions, 'avg_position' => $pos,
            ]);
        }
        $db->table('reach_search_site_daily')->insert(['connection_id' => $conn, 'metric_date' => '2026-10-21', 'clicks' => 27, 'impressions' => 290]);
    }

    /** @return array<string, mixed> */
    private function fetch(string $path, int $status = 200): array
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->get($path);
        $res->assertStatus($status);

        return json_decode((string) $res->getJSON(), true)['data'] ?? [];
    }

    public function testListGroupsSiteRowsByContentIdentity(): void
    {
        $data = $this->fetch('v1/intelligence/content/metrics');

        $this->assertTrue($data['available']);
        $this->assertSame(['from' => '2026-09-24', 'to' => '2026-10-21'], $data['period']);
        $this->assertSame([$this->blogIdentity, $this->plainIdentity], array_column($data['rows'], 'content_identity_id'));

        $blog = $data['rows'][0];
        $this->assertSame(17, $blog['clicks']);
        $this->assertSame(190, $blog['impressions']);
        $this->assertSame('GST explained', $blog['title']);
        $this->assertSame($this->itemId, $blog['content_item_id']);
        $this->assertSame(['https://www.example.com/blogs/gst-a/'], $blog['page_urls']);
        $this->assertEqualsWithDelta((300 + 300 + 160) / 190, $blog['avg_position'], 0.01);
        $this->assertSame(2, $blog['days']);

        $this->assertSame(['pages' => 1, 'clicks' => 7, 'impressions' => 70], $data['unmapped']);
        $this->assertSame(['clicks' => 20, 'impressions' => 220], $data['totals']);
    }

    public function testDetailRowsAddUpToTheListRow(): void
    {
        $data = $this->fetch('v1/intelligence/content/metrics/' . $this->blogIdentity);

        $this->assertSame('https://example.com/blogs/gst-a', $data['content']['canonical_url']);
        $this->assertSame(['2026-10-21', '2026-10-20'], array_column($data['daily'], 'date'));
        $this->assertSame([5, 12], array_column($data['daily'], 'clicks'));
        $this->assertSame('https://www.example.com/blogs/gst-a/', $data['daily'][0]['page_url']);
        $this->assertSame(2, $data['daily'][1]['rows']);
        $this->assertSame($data['summary']['clicks'], array_sum(array_column($data['daily'], 'clicks')));
    }

    public function testIdentityWithoutRowsAndUnknownIdentity(): void
    {
        $db = \Config\Database::connect();
        $db->table('reach_content_identities')->insert(['tenant_id' => 1, 'content_type' => 'page', 'source_id' => 6, 'canonical_url' => 'https://example.com/none']);
        $quiet = $this->fetch('v1/intelligence/content/metrics/' . (int) $db->insertID());
        $this->assertSame([], $quiet['daily']);
        $this->assertNull($quiet['summary']);

        $this->fetch('v1/intelligence/content/metrics/999999', 404);
    }
}
