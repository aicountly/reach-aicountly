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

        // queue is what the Social queue page lists (legacy approved /
        // scheduled / manual_queue); Content Studio items are content_queue.
        $this->assertSame(1, $social['queue'], 'the legacy manual_queue row the page shows');
        $this->assertSame(2, $social['content_queue'], 'scheduled + approved content items');
        $this->assertSame(2, $social['posted'], 'live content item + legacy posted');
        $this->assertSame(5, $social['total']);
    }

    private function seedJob(string $status, array $extra = []): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_jobs')->insert(array_merge([
            'job_type'   => 'blog.generate',
            'queue'      => 'default',
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));

        return (int) $db->insertID();
    }

    /**
     * Regression: the Bot activity card showed lifetime totals of reach_jobs
     * (QUEUE COMPLETED 70 447 / FAILED 19 684) plus the bot queue counted on
     * top of its own jobs. The queue block is now 24-hour windows and live
     * states, from reach_jobs alone.
     */
    public function testJobQueueBlockCountsTheLast24HoursAndLiveStates(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-3 days'));

        $this->seedJob('completed', ['completed_at' => date('Y-m-d H:i:s')]);
        $this->seedJob('completed', ['completed_at' => $old, 'updated_at' => $old]);  // history: not counted
        $this->seedJob('dead_letter', ['completed_at' => date('Y-m-d H:i:s'), 'job_type' => 'reach.content_overdue_escalation', 'error_message' => 'column ci.created_by does not exist']);
        $this->seedJob('dead_letter', ['completed_at' => $old, 'updated_at' => $old]);
        $this->seedJob('skipped', ['completed_at' => date('Y-m-d H:i:s'), 'error_message' => 'not configured']);
        $this->seedJob('pending', ['available_at' => date('Y-m-d H:i:s', strtotime('-20 minutes')), 'queue' => 'marketing_bot']);
        $this->seedJob('pending', ['available_at' => date('Y-m-d H:i:s', strtotime('+2 hours'))]);
        $this->seedJob('pending', ['available_at' => date('Y-m-d H:i:s'), 'attempts' => 2, 'error_message' => 'HTTP 503', 'job_type' => 'reach.seo_tracker']);
        $this->seedJob('processing', ['lease_expires_at' => date('Y-m-d H:i:s', strtotime('+4 minutes'))]);
        $this->seedJob('processing', ['lease_expires_at' => date('Y-m-d H:i:s', strtotime('-10 minutes'))]);

        // A bot dispatch has a queue row AND a job — it must count once.
        \Config\Database::connect()->table('reach_marketing_bot_queue')->insert([
            'action' => 'blog_ideas', 'status' => 'running',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $data = $this->fetchSummary();
        $jobs = $data['jobs'];

        $this->assertSame(24, $jobs['window_hours']);
        $this->assertSame(1, $jobs['completed'], 'only the completion inside 24 h');
        $this->assertSame(1, $jobs['failed'], 'only the dead letter inside 24 h');
        $this->assertSame(1, $jobs['skipped']);
        $this->assertSame(2, $jobs['queued'], 'ready now (incl. the retry)');
        $this->assertSame(1, $jobs['scheduled'], 'available later');
        $this->assertSame(1, $jobs['retrying']);
        $this->assertSame(1, $jobs['running'], 'live lease only — the bot queue row is not added');
        $this->assertSame(1, $jobs['stalled'], 'expired lease');
        $this->assertGreaterThanOrEqual(1150, $jobs['oldest_queued_secs']);

        $types = array_column($jobs['failing_types'], null, 'job_type');
        $this->assertSame(1, $types['reach.content_overdue_escalation']['failed']);
        $this->assertSame('column ci.created_by does not exist', $types['reach.content_overdue_escalation']['last_error']);
        $this->assertSame(1, $types['reach.seo_tracker']['retrying']);

        $queues = array_column($jobs['queues'], null, 'queue');
        $this->assertTrue($queues['marketing_bot']['undrained'], 'ready 20 min, nothing finished on it');
        $this->assertFalse($queues['default']['undrained']);

        $this->assertArrayNotHasKey('queue_completed', $data['bot'], 'lifetime totals are gone');
    }

    /** The number on each tile and the Job Monitor list it opens must agree. */
    public function testJobMonitorFiltersReturnTheRowsEachFigureCounted(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-3 days'));
        $this->seedJob('completed', ['completed_at' => date('Y-m-d H:i:s')]);
        $this->seedJob('completed', ['completed_at' => $old, 'updated_at' => $old]);
        $this->seedJob('dead_letter', ['completed_at' => date('Y-m-d H:i:s'), 'job_type' => 'x.fail']);
        $this->seedJob('failed', ['completed_at' => date('Y-m-d H:i:s'), 'job_type' => 'x.fail']);
        $this->seedJob('processing', ['lease_expires_at' => date('Y-m-d H:i:s', strtotime('-10 minutes'))]);
        $this->seedJob('pending', ['available_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);

        $summary = $this->fetchSummary()['jobs'];
        $headers = $this->authAs('super_admin');

        foreach (['completed', 'failed', 'stalled', 'queued', 'running', 'skipped'] as $figure) {
            $res  = $this->withHeaders($headers)->call('GET', 'v1/jobs?' . $summary['filters'][$figure]);
            $body = json_decode((string) $res->getJSON(), true);
            $this->assertSame($summary[$figure], $body['data']['total'], "{$figure}: tile and list disagree");
        }

        $type = $summary['failing_types'][0];
        $res  = $this->withHeaders($headers)->call('GET', 'v1/jobs?' . $type['filters']);
        $this->assertSame($type['failed'], json_decode((string) $res->getJSON(), true)['data']['total']);
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

    /**
     * Regression: "Content — scheduled 7" while "Upcoming calendar: No
     * upcoming items". The seven were publish_queued (no date at all), and
     * blogs scheduled through a publication deployment carried their date
     * only on the deployment.
     */
    public function testScheduledTileAndCalendarAgree(): void
    {
        $db       = \Config\Database::connect();
        $nextWeek = date('Y-m-d', strtotime('+7 days'));

        // Stuck in the publish queue: publishing, not scheduled.
        $this->seedContentItem('blog', 'publish_queued');

        // Scheduled through a deployment (blog SCHEDULE_PUBLISH path).
        $viaDeployment = $this->seedContentItem('blog', 'scheduled');
        $db->table('reach_publication_connections')->insert([
            'connection_key' => 'dash-test-' . bin2hex(random_bytes(3)),
            'display_name'   => 'Dash test',
            'enabled'        => true,
        ]);
        $connectionId = (int) $db->insertID();
        $db->table('reach_content_versions')->insert(['content_item_id' => $viaDeployment, 'title' => 'v1']);
        $versionId = (int) $db->insertID();
        $db->table('reach_publication_deployments')->insert([
            'content_item_id' => $viaDeployment,
            'content_version_id' => $versionId,
            'connection_id'   => $connectionId,
            'operation'       => 'schedule',
            'status'          => 'queued',
            'idempotency_key' => 'dash-' . bin2hex(random_bytes(4)),
            'scheduled_at'    => $nextWeek . ' 10:00:00',
        ]);

        // Counted as scheduled but with no future date.
        $this->seedContentItem('blog', 'scheduled');
        $this->seedContentItem('knowledge_base', 'ready_for_publication', ['scheduled_at' => date('Y-m-d', strtotime('-3 days')) . ' 09:00:00']);

        $data = $this->fetchSummary();

        $this->assertSame(3, $data['content']['scheduled'], 'publish_queued is not scheduled');
        $this->assertSame(1, $data['blog']['pending_publishing']);

        $this->assertCount(1, $data['calendar_upcoming']);
        $this->assertSame('deployment', $data['calendar_upcoming'][0]['source']);
        $this->assertSame($nextWeek, $data['calendar_upcoming'][0]['date']);

        $this->assertSame(2, $data['calendar_overdue']['count'], 'scheduled = upcoming + overdue/undated');
        $reasons = array_column($data['calendar_overdue']['items'], 'reason');
        sort($reasons);
        $this->assertSame(['date_passed', 'no_date'], $reasons);
    }

    public function testCampaignRunningCountsActiveCampaigns(): void
    {
        $db = \Config\Database::connect();
        foreach (['active', 'dispatching', 'draft'] as $status) {
            $db->table('reach_campaigns')->insert([
                'name' => 'C ' . $status, 'campaign_type' => 'email', 'status' => $status,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->assertSame(2, $this->fetchSummary()['campaigns']['running']);
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
        $this->assertSame(0, $data['social_queue'], 'a content item is not in the Social queue page');
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
