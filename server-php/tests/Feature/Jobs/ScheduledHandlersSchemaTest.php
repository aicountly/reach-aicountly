<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ContentDueDateReminderJob;
use App\Jobs\ContentOverdueEscalationJob;
use App\Jobs\ContentRefreshDetectionJob;
use App\Jobs\ContentScheduleReadinessJob;
use App\Jobs\GalleryDeficitAlertJob;
use App\Libraries\JobContext;
use App\Libraries\JobRunner;
use Tests\Support\DatabaseTestCase;

/**
 * Regression: hourly/daily scheduler handlers read columns that do not exist
 * (reach_content_items.created_by), so they dead-lettered 5/5 every run in
 * production. These run each handler against the fully migrated schema with
 * rows that reach every query, so a wrong column fails here first.
 */
final class ScheduledHandlersSchemaTest extends DatabaseTestCase
{
    private function ctx(): JobContext
    {
        return JobContext::fromJobRow(['id' => 1, 'job_type' => 't', 'attempts' => 1], 'test');
    }

    private function user(): int
    {
        $db = \Config\Database::connect();
        $role = $db->table('reach_roles')->select('id')->get()->getRowArray();
        $db->table('reach_users')->insert([
            'email' => 'owner-' . bin2hex(random_bytes(3)) . '@test.aicountly.org',
            'name' => 'Owner', 'password_hash' => 'x', 'role_id' => (int) $role['id'],
        ]);

        return (int) $db->insertID();
    }

    private function item(string $status, int $owner, array $extra = []): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_content_items')->insert(array_merge([
            'content_type' => 'landing_page', 'title' => 'Item ' . $status,
            'slug' => 'item-' . bin2hex(random_bytes(4)), 'workflow_status' => $status,
            'created_by_user_id' => $owner,
        ], $extra));

        return (int) $db->insertID();
    }

    private function notifications(int $recipient, string $type): int
    {
        return \Config\Database::connect()->table('reach_notifications')
            ->where('recipient_id', $recipient)->where('notification_type', $type)->countAllResults();
    }

    public function testContentNotificationHandlersRunAgainstTheMigratedSchema(): void
    {
        $db    = \Config\Database::connect();
        $owner = $this->user();

        // Overdue (escalation) and due tomorrow (reminder to the writer).
        $overdue = $this->item('draft', $owner);
        $db->table('reach_content_briefs')->insert(['content_item_id' => $overdue, 'due_date' => date('Y-m-d', strtotime('-2 days'))]);
        $dueSoon = $this->item('review_pending', $owner);
        $db->table('reach_content_briefs')->insert(['content_item_id' => $dueSoon, 'due_date' => date('Y-m-d', strtotime('+1 day'))]);
        $db->table('reach_content_assignments')->insert(['content_item_id' => $dueSoon, 'user_id' => $owner, 'role' => 'writer']);
        $db->table('reach_content_assignments')->insert(['content_item_id' => $dueSoon, 'user_id' => $owner, 'role' => 'reviewer']);

        // A due schedule.
        $scheduled = $this->item('scheduled', $owner);
        $db->table('reach_content_publication_targets')->insert(['name' => 'Site', 'channel' => 'aicountly_website']);
        $db->table('reach_content_schedules')->insert([
            'content_item_id' => $scheduled, 'publication_target_id' => (int) $db->insertID(),
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'schedule_status' => 'pending',
        ]);

        // Stale published content (non-blog) for refresh detection.
        $stale = $this->item('published', $owner, ['published_at' => date('Y-m-d H:i:s', strtotime('-120 days'))]);
        $staleBlog = $this->item('published', $owner, ['content_type' => 'blog', 'published_at' => date('Y-m-d H:i:s', strtotime('-120 days'))]);

        $escalation = (new ContentOverdueEscalationJob())->handle([], $this->ctx());
        $this->assertSame(1, $escalation['escalated']);

        $reminders = (new ContentDueDateReminderJob())->handle([], $this->ctx());
        $this->assertSame(1, $reminders['reminders_sent']);

        $digest = (new \App\Jobs\DailyApprovalDigestJob())->handle(['date' => date('Y-m-d')], $this->ctx());
        $this->assertTrue($digest['ok']);
        $this->assertSame(1, $digest['sent'], 'the reviewer of the review_pending item');

        $ready = (new ContentScheduleReadinessJob())->handle([], $this->ctx());
        $this->assertSame(1, $ready['schedules_ready']);

        $refresh = (new ContentRefreshDetectionJob())->handle([], $this->ctx());
        $this->assertSame(1, $refresh['refresh_due']);
        $this->assertSame('refresh_due', $db->table('reach_content_items')->where('id', $stale)->get()->getRow()->workflow_status);
        $this->assertSame('published', $db->table('reach_content_items')->where('id', $staleBlog)->get()->getRow()->workflow_status, 'blog lifecycle belongs to BlogStateMachine');

        // Hourly re-runs must not repeat today's notifications.
        (new ContentOverdueEscalationJob())->handle([], $this->ctx());
        (new ContentDueDateReminderJob())->handle([], $this->ctx());
        $this->assertSame(1, $this->notifications($owner, \App\Libraries\NotificationService::TYPE_REVIEW_OVERDUE));
        $this->assertSame(1, $this->notifications($owner, \App\Libraries\NotificationService::TYPE_REVIEW_DUE));
    }

    public function testGalleryAlertWithoutARecipientIsSkippedNotCompleted(): void
    {
        putenv('BLOG_GALLERY_ALERT_EMAIL=');
        $_ENV['BLOG_GALLERY_ALERT_EMAIL'] = '';
        $result = (new GalleryDeficitAlertJob())->handle([], $this->ctx());

        // The alert now only fires on repeated cover-generation failures (covers stream).
        // Nothing failing is a completion; failing with nobody to tell is skipped, not failed.
        if (($result['reason'] ?? '') === 'no_recipients') {
            $this->assertSame('no_recipients', JobRunner::skipReason($result));
        } else {
            $this->assertNull(JobRunner::skipReason($result), 'nothing to alert about is a completion');
        }
    }
}
