<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Libraries\Analytics\AnalyticsRecordsService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The Analytics page's internal tiles and the lists they open share one
 * predicate: Blog drafts / published / approvals pending count the legacy
 * reach_blog_posts table (still written by the bot's "Generate blog draft")
 * and Engagement imported counts non-internal reach_analytics_snapshots.
 */
final class AnalyticsSummaryRecordsTest extends ApiTestCase
{
    private function blog(string $status, array $extra = []): int
    {
        $db = Database::connect();
        $db->table('reach_blog_posts')->insert(array_merge([
            'title'      => 'Legacy ' . $status,
            'slug'       => 'legacy-' . $status . '-' . bin2hex(random_bytes(4)),
            'content'    => '<p>Body of the <strong>post</strong></p>',
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));

        return (int) $db->insertID();
    }

    private function snapshot(string $source, string $capturedAt, array $metrics): int
    {
        $db = Database::connect();
        $db->table('reach_analytics_snapshots')->insert([
            'source' => $source, 'captured_at' => $capturedAt, 'metrics' => json_encode($metrics),
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $response = $this->withHeaders($this->authAs('analyst'))->call('GET', 'v1/analytics/summary');
        $this->assertSame(200, $response->response()->getStatusCode());

        return json_decode((string) $response->getJSON(), true)['data'];
    }

    public function testBlogTilesMatchTheirLists(): void
    {
        $this->blog('draft', ['approval_status' => 'pending', 'bot_generated' => true]);
        $this->blog('seo_review');
        $this->blog('published', ['published_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
        $this->blog('published', ['published_at' => date('Y-m-d H:i:s', strtotime('-60 days'))]);
        $this->blog('archived');

        $summary = $this->summary();
        $this->assertSame(2, $summary['blog_drafts']);
        $this->assertSame(1, $summary['blog_published']);
        $this->assertSame(1, $summary['approval_pending']);

        $records = new AnalyticsRecordsService();
        foreach (['drafts' => 'blog_drafts', 'published' => 'blog_published', 'approval_pending' => 'approval_pending'] as $bucket => $key) {
            $list = $records->legacyBlogPosts(['bucket' => $bucket]);
            $this->assertSame($summary[$key], $list['total'], "{$bucket} list matches its tile");
            $this->assertCount($list['total'], $list['items']);
            $this->assertSame($summary[$key], $list['counts'][$bucket]);
        }

        $pending = $records->legacyBlogPosts(['bucket' => 'approval_pending'])['items'][0];
        $this->assertTrue($pending['bot_generated']);
        $this->assertSame('Body of the post', $pending['content_preview']);
        $this->assertArrayNotHasKey('content', $pending);

        $this->assertSame(1, $records->legacyBlogPosts(['q' => 'SEO_rev'])['total'], 'Search is case-insensitive on title/slug.');
        $this->assertSame(1, $records->legacyBlogPosts(['status' => 'archived'])['total']);
    }

    public function testEngagementTileMatchesTheSnapshotList(): void
    {
        $recent = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->snapshot('ga4', $recent, ['sessions' => 120, 'users' => 80]);
        $this->snapshot('linkedin', $recent, ['impressions' => 900]);
        $this->snapshot('internal', $recent, ['campaigns' => 3]);
        $this->snapshot('meta', date('Y-m-d H:i:s', strtotime('-45 days')), ['reach' => 5]);

        $summary = $this->summary();
        $this->assertSame(2, $summary['engagement_imported']);

        $records = new AnalyticsRecordsService();
        $list    = $records->snapshots(['source' => 'external', 'days' => 30]);
        $this->assertSame(2, $list['total']);
        $this->assertSame(['ga4' => 1, 'internal' => 1, 'linkedin' => 1], array_intersect_key($list['by_source'], ['ga4' => 1, 'internal' => 1, 'linkedin' => 1]));
        $ga4 = array_values(array_filter($list['items'], static fn (array $s): bool => $s['source'] === 'ga4'))[0];
        $this->assertEquals(['sessions' => 120, 'users' => 80], $ga4['metrics'], 'JSONB keeps the payload (not its key order).');

        $this->assertSame(1, $records->snapshots(['source' => 'meta'])['total'], 'No window: every meta snapshot.');
        $this->assertSame(1, $records->snapshots(['id' => $ga4['id']])['total']);
        $old = date('Y-m-d', strtotime('-45 days'));
        $this->assertSame(1, $records->snapshots(['from' => $old, 'to' => $old])['total']);
    }

    /** The record lists and the GA4 explorer are routed and permission-gated like the summary. */
    public function testRecordEndpointsAreRoutedForAnalysts(): void
    {
        $this->blog('draft', ['approval_status' => 'pending']);
        $h = $this->authAs('analyst');

        $posts = $this->withHeaders($h)->call('GET', 'v1/analytics/legacy-blog-posts?bucket=drafts');
        $this->assertSame(200, $posts->response()->getStatusCode());
        $this->assertSame(1, json_decode((string) $posts->getJSON(), true)['data']['total']);

        $this->assertSame(200, $this->withHeaders($h)->call('GET', 'v1/analytics/snapshots?source=external&days=30')->response()->getStatusCode());
        // GA4 is not configured in tests: the explorer answers honestly instead of erroring.
        $this->assertSame(200, $this->withHeaders($h)->call('GET', 'v1/analytics/traffic/explore?group_by=channel')->response()->getStatusCode());
    }
}
