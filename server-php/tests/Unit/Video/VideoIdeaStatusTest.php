<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Enums\VideoIdeaStatus;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoIdeaStatusTest extends CIUnitTestCase
{
    public function testAllCasesHaveUniqueValues(): void
    {
        $values = array_map(fn($c) => $c->value, VideoIdeaStatus::cases());
        $this->assertSame(count($values), count(array_unique($values)), 'VideoIdeaStatus values must be unique');
    }

    public function testExpectedCasesExist(): void
    {
        $expected = ['draft', 'ready', 'accepted', 'rejected', 'archived', 'converted'];
        $actual   = array_map(fn($c) => $c->value, VideoIdeaStatus::cases());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testDraftCanTransitionToReady(): void
    {
        $this->assertTrue(VideoIdeaStatus::Draft->canTransitionTo(VideoIdeaStatus::Ready));
    }

    public function testReadyCanTransitionToAccepted(): void
    {
        $this->assertTrue(VideoIdeaStatus::Ready->canTransitionTo(VideoIdeaStatus::Accepted));
    }

    public function testReadyCanTransitionToRejected(): void
    {
        $this->assertTrue(VideoIdeaStatus::Ready->canTransitionTo(VideoIdeaStatus::Rejected));
    }

    public function testAcceptedCanTransitionToConverted(): void
    {
        $this->assertTrue(VideoIdeaStatus::Accepted->canTransitionTo(VideoIdeaStatus::Converted));
    }

    public function testConvertedIsTerminal(): void
    {
        $this->assertTrue(VideoIdeaStatus::Converted->isTerminal());
        $this->assertEmpty(VideoIdeaStatus::Converted->canTransitionTo(VideoIdeaStatus::Draft) ? ['x'] : []);
    }

    public function testArchivedIsTerminal(): void
    {
        $this->assertTrue(VideoIdeaStatus::Archived->isTerminal());
    }

    public function testRejectedIsTerminal(): void
    {
        $this->assertTrue(VideoIdeaStatus::Rejected->isTerminal());
    }

    public function testDraftCannotTransitionDirectlyToConverted(): void
    {
        $this->assertFalse(VideoIdeaStatus::Draft->canTransitionTo(VideoIdeaStatus::Converted));
    }
}
