<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Enums\VideoProjectStatus;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoProjectStatusTest extends CIUnitTestCase
{
    public function testAllCasesHaveUniqueValues(): void
    {
        $values = array_map(fn($c) => $c->value, VideoProjectStatus::cases());
        $this->assertSame(count($values), count(array_unique($values)), 'VideoProjectStatus values must be unique');
    }

    public function testExpectedCasesExist(): void
    {
        $expected = [
            'draft', 'script_generating', 'script_draft', 'script_in_review', 'script_approved',
            'render_queued', 'rendering', 'rendered', 'publish_queued', 'publishing', 'published',
            'generation_failed', 'validation_blocked', 'changes_requested',
            'render_failed', 'publish_failed', 'cancelled', 'withdrawn',
        ];
        $actual = array_map(fn($c) => $c->value, VideoProjectStatus::cases());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testTransitionMapIsComplete(): void
    {
        foreach (VideoProjectStatus::cases() as $status) {
            $transitions = VideoProjectStatus::validTransitions($status);
            $this->assertIsArray($transitions, "validTransitions must return array for {$status->value}");
        }
    }

    public function testHappyPathTransitions(): void
    {
        $happy = [
            [VideoProjectStatus::Draft,           VideoProjectStatus::ScriptGenerating],
            [VideoProjectStatus::ScriptGenerating, VideoProjectStatus::ScriptDraft],
            [VideoProjectStatus::ScriptDraft,     VideoProjectStatus::ScriptInReview],
            [VideoProjectStatus::ScriptInReview,  VideoProjectStatus::ScriptApproved],
            [VideoProjectStatus::ScriptApproved,  VideoProjectStatus::RenderQueued],
            [VideoProjectStatus::RenderQueued,    VideoProjectStatus::Rendering],
            [VideoProjectStatus::Rendering,       VideoProjectStatus::Rendered],
            [VideoProjectStatus::Rendered,        VideoProjectStatus::PublishQueued],
            [VideoProjectStatus::PublishQueued,   VideoProjectStatus::Publishing],
            [VideoProjectStatus::Publishing,      VideoProjectStatus::Published],
        ];
        foreach ($happy as [$from, $to]) {
            $this->assertTrue($from->canTransitionTo($to), "{$from->value} → {$to->value} must be valid");
        }
    }

    public function testCancelledIsTerminal(): void
    {
        $this->assertTrue(VideoProjectStatus::Cancelled->isTerminal());
        $this->assertEmpty(VideoProjectStatus::validTransitions(VideoProjectStatus::Cancelled));
    }

    public function testWithdrawnIsTerminal(): void
    {
        $this->assertTrue(VideoProjectStatus::Withdrawn->isTerminal());
    }

    public function testPublishedIsTerminal(): void
    {
        $this->assertTrue(VideoProjectStatus::Published->isTerminal());
    }

    public function testDraftCannotTransitionDirectlyToRendering(): void
    {
        $this->assertFalse(VideoProjectStatus::Draft->canTransitionTo(VideoProjectStatus::Rendering));
    }
}
