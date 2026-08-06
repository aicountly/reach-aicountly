<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

/**
 * The marketing dashboard must report what the portal actually holds.
 *
 * Regression: every tile read 0 on a live portal because the summary counted
 * only the legacy reach_blog_posts / reach_social_posts tables, while all
 * authoring had moved to reach_content_items (Content Studio + blog
 * automation). These tests seed both generations of tables and assert the
 * union — including that a legacy post already migrated across is counted
 * exactly once.
 */
final class DashboardSummaryTest extends ApiTestCase
{
    private function seedContentItem(string $type, string $status, array $extra = []): int
    {
        $db   = \Config\Database::connect();
        $slug = strtolower($type . '-' . $status . '-' . bin2hex(random_bytes(4)));

        $db->table('reach_content_items')->insert(array_merge([
            'content_type'    => $type,
            'title'           => ucfirst($type) . ' ' . $status,
            'slug'            => $slug,
            'workflow_status' => $status,
        ], $extra));

        return (int) $db->insertID();
    }

    private function seedLegacyBlog(string $status, array $extra = []): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_blog_posts')->insert(array_merge([
            'title'      => 'Legacy ' . $status,
            'slug'       => 'legacy-' . $status . '-' . bin2hex(random_bytes(4)),
            'content'    => 'body',
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));

        return (int) $db->insertID();
    }

    /** @return array<string, mixed> */
    private function fetchSummary(): array
    {
        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/dashboard/summary');
        $this->assertSame(200, $response->response()->getStatusCode());

        $body = json_decode((string) $response->getJSON(), true);
        $this->assertTrue($body['ok']);

        return $body['data'];
    }

    public function testBlogTilesCountContentStudioItems(): void
    {
        $this->seedContentItem('blog', 'draft');
        $this->seedContentItem('blog', 'draft_generating');
        $this->seedContentItem('blog', 'seo_review');
        $this->seedContentItem('blog', 'internal_review');
        $this->seedContentItem('blog', 'approved');
        $this->seedContentItem('blog', 'live');
        $this->seedContentItem('blog', 'published');
        $this->seedContentItem('blog', 'publishing');
        $this->seedContentItem('blog', 'topic_candidate');

        $blog = $this->fetchSummary()['blog'];

        $this->assertSame(2, $blog['drafts'], 'draft + draft_generating');
        $this->assertSame(2, $blog['in_review'], 'seo_review + internal_review');
        $this->assertSame(1, $blog['approved']);
        $this->assertSame(2, $blog['published'], 'published + live');
        $this->assertSame(1, $blog['pending_publishing']);
        $this->assertSame(1, $blog['ideas'], 'topic_candidate is a roadmap idea');
        $this->assertSame(9, $blog['total']);
    }

    public function testLegacyBlogRowsAreIncludedButMigratedOnesAreNotDoubleCounted(): void
    {
        $db = \Config\Database::connect();

        // Never migrated — must still show up.
        $this->seedLegacyBlog('draft');

        // Migrated via the content_item_id back-pointer.
        $itemA = $this->seedContentItem('blog', 'draft');
        $this->seedLegacyBlog('draft', ['content_item_id' => $itemA]);

        // Migrated via the legacy migration map.
        $itemB    = $this->seedContentItem('blog', 'draft');
        $legacyId = $this->seedLegacyBlog('draft');
        $db->table('reach_blog_legacy_migration_map')->insert([
            'legacy_blog_post_id' => $legacyId,
            'content_item_id'     => $itemB,
            'status'              => 'completed',
            'migrated_at'         => date('Y-m-d H:i:s'),
        ]);

        $blog = $this->fetchSummary()['blog'];

        // 2 content items + 1 unmigrated legacy row = 3, not 5.
        $this->assertSame(3, $blog['drafts']);
        $this->assertSame(3, $blog['total']);
    }

    public function testLegacyPendingPublishingStillCounts(): void
    {
        $this->seedLegacyBlog('approved', ['publishing_status' => 'pending_publishing']);

        $blog = $this->fetchSummary()['blog'];

        $this->assertSame(1, $blog['pending_publishing']);
        $this->assertSame(1, $blog['approved']);
    }

    public function testSocialQueueUnionsContentItemsAndLegacyPosts(): void
    {
        $db = \Config\Database::connect();

        $this->seedContentItem('social_post', 'scheduled');
        $this->seedContentItem('social_post', 'approved');
        $this->seedContentItem('social_post', 'live');

        foreach ([['manual_queue'], ['posted']] as [$status]) {
            $db->table('reach_social_posts')->insert([
                'channel'    => 'linkedin',
                'content'    => 'Legacy social post',
                'status'     => $status,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $social = $this->fetchSummary()['social'];

        $this->assertSame(3, $social['queue'], '2 content items + 1 legacy manual_queue');
        $this->assertSame(2, $social['posted'], 'live content item + legacy posted');
        $this->assertSame(5, $social['total']);
    }

    public function testBotTileIncludesGenericJobRunner(): void
    {
        $db = \Config\Database::connect();

        $db->table('reach_marketing_bot_queue')->insert([
            'action'     => 'blog_ideas',
            'status'     => 'running',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        foreach (['processing', 'processing', 'completed', 'dead_letter'] as $status) {
            $db->table('reach_jobs')->insert([
                'job_type'   => 'blog.generate',
                'status'     => $status,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $bot = $this->fetchSummary()['bot'];

        $this->assertSame(3, $bot['queue_running'], '1 bot queue + 2 jobs processing');
        $this->assertSame(1, $bot['queue_completed']);
        $this->assertSame(1, $bot['queue_failed'], 'dead_letter counts as failed');
    }

    public function testUpcomingCalendarMergesScheduledContentAndLegacyItems(): void
    {
        $db       = \Config\Database::connect();
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $nextWeek = date('Y-m-d', strtotime('+7 days'));

        $this->seedContentItem('blog', 'scheduled', ['scheduled_at' => $nextWeek . ' 09:00:00']);
        $this->seedContentItem('blog', 'scheduled', ['scheduled_at' => date('Y-m-d', strtotime('-2 days')) . ' 09:00:00']);

        $db->table('reach_content_calendar_items')->insert([
            'date'       => $tomorrow,
            'item_kind'  => 'social',
            'title'      => 'Legacy calendar entry',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $upcoming = $this->fetchSummary()['calendar_upcoming'];

        $this->assertCount(2, $upcoming, 'past scheduled items are excluded');
        $this->assertSame($tomorrow, $upcoming[0]['date'], 'sorted by date across sources');
        $this->assertSame('Legacy calendar entry', $upcoming[0]['title']);
        $this->assertSame($nextWeek, $upcoming[1]['date']);
    }

    public function testSidebarCountsUseTheSameSources(): void
    {
        $this->seedContentItem('blog', 'draft');
        $this->seedContentItem('blog', 'review_pending');
        $this->seedContentItem('social_post', 'scheduled');

        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/dashboard/counts');
        $this->assertSame(200, $response->response()->getStatusCode());

        $data = json_decode((string) $response->getJSON(), true)['data'];

        $this->assertSame(2, $data['blog']);
        $this->assertSame(1, $data['social_queue']);
        $this->assertArrayHasKey('approvals', $data);
        $this->assertArrayHasKey('leads_pending_push', $data);
    }

    public function testSummaryCarriesAGeneratedAtStamp(): void
    {
        $data = $this->fetchSummary();

        $this->assertArrayHasKey('generated_at', $data);
        $this->assertNotFalse(strtotime((string) $data['generated_at']));
    }
}
