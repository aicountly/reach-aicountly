<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\ContentOverdueEscalationJob;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Seo\DataForSeoSerpClient;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use App\Libraries\JobContext;
use App\Libraries\Reports\DailyReportBuilder;
use App\Models\UserModel;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Jobs on the `notifications` queue only started running once the worker
 * drained that queue. The overdue escalation read a column that does not
 * exist, and both hourly reminder jobs re-notified the same person every hour.
 */
final class NotificationJobsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
    }

    private function item(int $ownerId, string $status, ?string $publishedAt = null): int
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => 'Item ' . $status, 'slug' => 'item-' . $status . '-' . mt_rand(),
            'workflow_status' => $status, 'created_by_user_id' => $ownerId, 'published_at' => $publishedAt,
        ]);

        return (int) $db->insertID();
    }

    public function testOverdueEscalationRunsAndNotifiesOncePerDay(): void
    {
        $this->authAs('reach_admin');
        $owner = (int) (new UserModel())->where('email', 'reach_admin@test.aicountly.org')->first()['id'];
        $id    = $this->item($owner, 'draft');
        Database::connect()->table('reach_content_briefs')->insert(['content_item_id' => $id, 'due_date' => date('Y-m-d', strtotime('-2 days'))]);

        $ctx = JobContext::fromJobRow(['id' => 1, 'job_type' => 'reach.content_overdue_escalation', 'attempts' => 1], 'test');
        $job = new ContentOverdueEscalationJob();

        $this->assertSame(1, $job->handle([], $ctx)['escalated']);
        $this->assertSame(0, $job->handle([], $ctx)['escalated'], 'The next hourly run does not repeat it.');
        $this->assertSame(1, (int) Database::connect()->table('reach_notifications')->where('entity_id', $id)->countAllResults());
    }

    public function testReportCountsContentPublishedInTheWindow(): void
    {
        $this->authAs('reach_admin');
        $owner = (int) (new UserModel())->where('email', 'reach_admin@test.aicountly.org')->first()['id'];
        $this->item($owner, 'published', '2026-10-17 00:30:00+00');   // inside 24h before 08:00 IST
        $this->item($owner, 'published', '2026-10-15 00:30:00+00');   // outside

        $db      = Database::connect();
        $summary = (new DailyReportBuilder($db, null, new SeoTrackerService($db, new DataForSeoSerpClient(null, null)), static fn (): bool => false))
            ->build('2026-10-17', new \DateTimeImmutable('2026-10-17 10:00:00', new \DateTimeZone('Asia/Kolkata')));

        $this->assertSame(1, $summary['sections']['content']['counts']['blog']);
        $this->assertSame('Item published', $summary['sections']['content']['items'][0]['title']);
    }
}
