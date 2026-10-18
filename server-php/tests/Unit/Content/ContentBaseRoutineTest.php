<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Commands\ReachSchedule;
use App\Libraries\Content\Base\ContentBaseEntries;
use App\Libraries\Content\Base\RoutineActivity;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure parts of the living content base: which routine an automation call
 * belongs to, the quiet-routine sentences, type names, and the timetable.
 *
 * @internal
 */
final class ContentBaseRoutineTest extends CIUnitTestCase
{
    public function testAutomationCallsAreCreditedToTheirContentType(): void
    {
        $this->assertSame(['knowledge_base', 'kb-plan'], RoutineActivity::classify('/api/v1/automation/kb-plan', null));
        $this->assertSame(['blog', 'blog-drafts'], RoutineActivity::classify('v1/automation/blog-drafts', null));
        $this->assertSame(['community', 'community-answers'], RoutineActivity::classify('api/v1/automation/community-answers', null));
        $this->assertSame(['release_note', 'release-plan'], RoutineActivity::classify('v1/automation/release-plan', null));
        $this->assertSame(['content_base', 'content-base/next'], RoutineActivity::classify('v1/automation/content-base/next', 'kb'), 'the queue is read by every routine');
        $this->assertSame(['content_base', 'content-base/propose'], RoutineActivity::classify('v1/automation/content-base/propose', null));
        $this->assertNull(RoutineActivity::classify('v1/automation/link-registry', null), 'shared endpoints credit nobody');
        $this->assertNull(RoutineActivity::classify('v1/content-base', null));
    }

    public function testQuietRoutinesReadAsInformation(): void
    {
        $lines = RoutineActivity::staleLines([
            'blog'           => ['stale' => false, 'routine' => 'reach-blog', 'last_activity_at' => '2026-10-18 08:00:00', 'hours_since' => 2.0, 'threshold_hours' => 36],
            'knowledge_base' => ['stale' => true, 'routine' => 'reach-kb', 'last_activity_at' => null, 'hours_since' => null, 'threshold_hours' => 36],
            'community'      => ['stale' => true, 'routine' => 'reach-community-qa', 'last_activity_at' => '2026-10-16 08:00:00', 'hours_since' => 50.0, 'threshold_hours' => 36],
        ]);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('ever reached Reach (reach-kb)', $lines[0]);
        $this->assertStringContainsString('for 50 h (reach-community-qa', $lines[1]);
    }

    public function testTypeNames(): void
    {
        $this->assertSame('knowledge_base', ContentBaseEntries::normalizeType('kb'));
        $this->assertSame('knowledge_base', ContentBaseEntries::normalizeType('knowledge-base'));
        $this->assertSame('community', ContentBaseEntries::normalizeType('community'));
        $this->assertSame('blog', ContentBaseEntries::normalizeType('BLOG'));
        $this->assertNull(ContentBaseEntries::normalizeType('video'));
    }

    public function testContentBaseSyncsHourlyAndTheRoadmapIsRescoredDaily(): void
    {
        $types = static function (string $utc): array {
            $out = [];
            foreach (ReachSchedule::plannedJobs(new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))) as $job) {
                $out[$job['type']] = $job;
            }

            return $out;
        };

        $a = $types('2026-10-18 10:05:00')['reach.content_base_sync']['opts']['idempotency_key'];
        $b = $types('2026-10-18 10:55:00')['reach.content_base_sync']['opts']['idempotency_key'];
        $c = $types('2026-10-18 11:05:00')['reach.content_base_sync']['opts']['idempotency_key'];
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);

        // 20:00 UTC = 01:30 IST on the next IST day.
        $refresh = $types('2026-10-18 20:00:00')['reach.roadmap_refresh'] ?? null;
        $this->assertNotNull($refresh);
        $this->assertSame('roadmap-refresh-2026-10-19', $refresh['opts']['idempotency_key']);
        $this->assertSame(['date' => '2026-10-19'], $refresh['payload']);
        $this->assertArrayNotHasKey('reach.roadmap_refresh', $types('2026-10-18 21:00:00'));

        $registry = new \App\Libraries\JobHandlerRegistry();
        $this->assertTrue($registry->has('reach.roadmap_refresh'));
        $this->assertTrue($registry->has('reach.content_base_sync'));
    }

    public function testTheNewSettingsAreRegistered(): void
    {
        $registry = \App\Libraries\Settings\ReachConfig::registry();
        $this->assertSame(36, $registry['automation.content_base.stale_hours']['default']);
        $this->assertSame(20, $registry['automation.content_base.propose_daily_limit']['default']);
        $this->assertTrue($registry['automation.roadmap.routine_handoff']['default']);
    }
}
