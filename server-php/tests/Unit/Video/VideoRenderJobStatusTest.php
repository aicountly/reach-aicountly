<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Enums\VideoRenderJobStatus;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoRenderJobStatusTest extends CIUnitTestCase
{
    public function testAllCasesHaveUniqueValues(): void
    {
        $values = array_map(fn($c) => $c->value, VideoRenderJobStatus::cases());
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function testExpectedCasesExist(): void
    {
        $expected = ['queued', 'reserved', 'rendering', 'rendered', 'failed', 'cancelled', 'dead_letter'];
        $actual   = array_map(fn($c) => $c->value, VideoRenderJobStatus::cases());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testQueuedCanTransitionToReserved(): void
    {
        $this->assertTrue(VideoRenderJobStatus::Queued->canTransitionTo(VideoRenderJobStatus::Reserved));
    }

    public function testRenderedIsTerminal(): void
    {
        $this->assertTrue(VideoRenderJobStatus::Rendered->isTerminal());
    }

    public function testDeadLetterIsTerminal(): void
    {
        $this->assertTrue(VideoRenderJobStatus::DeadLetter->isTerminal());
    }

    public function testCancelledIsTerminal(): void
    {
        $this->assertTrue(VideoRenderJobStatus::Cancelled->isTerminal());
    }

    public function testFailedCanRetryToQueued(): void
    {
        $this->assertTrue(VideoRenderJobStatus::Failed->canTransitionTo(VideoRenderJobStatus::Queued));
    }

    public function testFailedCanTransitionToDeadLetter(): void
    {
        $this->assertTrue(VideoRenderJobStatus::Failed->canTransitionTo(VideoRenderJobStatus::DeadLetter));
    }
}
