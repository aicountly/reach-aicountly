<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityQuestionStatus;
use PHPUnit\Framework\TestCase;

final class CommunityQuestionStatusTest extends TestCase
{
    public function testIntakeCanTransitionToTriaged(): void
    {
        $this->assertTrue(CommunityQuestionStatus::Intake->canTransitionTo(CommunityQuestionStatus::Triaged));
    }

    public function testIntakeCanTransitionToArchived(): void
    {
        $this->assertTrue(CommunityQuestionStatus::Intake->canTransitionTo(CommunityQuestionStatus::Archived));
    }

    public function testIntakeCanTransitionToDuplicateMerged(): void
    {
        $this->assertTrue(CommunityQuestionStatus::Intake->canTransitionTo(CommunityQuestionStatus::DuplicateMerged));
    }

    public function testIntakeCannotTransitionToPublished(): void
    {
        $this->assertFalse(CommunityQuestionStatus::Intake->canTransitionTo(CommunityQuestionStatus::Published));
    }

    public function testTriagedCanTransitionToDraftRequested(): void
    {
        $this->assertTrue(CommunityQuestionStatus::Triaged->canTransitionTo(CommunityQuestionStatus::DraftRequested));
    }

    public function testPublishedCanTransitionToWithdrawn(): void
    {
        $this->assertTrue(CommunityQuestionStatus::Published->canTransitionTo(CommunityQuestionStatus::Withdrawn));
    }

    public function testArchivedIsTerminal(): void
    {
        $this->assertTrue(CommunityQuestionStatus::Archived->isTerminal());
    }

    public function testDuplicateMergedIsTerminal(): void
    {
        $this->assertTrue(CommunityQuestionStatus::DuplicateMerged->isTerminal());
    }

    public function testIntakeIsNotTerminal(): void
    {
        $this->assertFalse(CommunityQuestionStatus::Intake->isTerminal());
    }

    public function testArchivedHasNoValidTransitions(): void
    {
        foreach (CommunityQuestionStatus::cases() as $target) {
            $this->assertFalse(CommunityQuestionStatus::Archived->canTransitionTo($target));
        }
    }

    public function testPublishedIsPubliclyVisible(): void
    {
        $this->assertTrue(CommunityQuestionStatus::Published->isPubliclyVisible());
    }

    public function testIntakeIsNotPubliclyVisible(): void
    {
        $this->assertFalse(CommunityQuestionStatus::Intake->isPubliclyVisible());
    }

    public function testFromStringReturnsCorrectEnum(): void
    {
        $status = CommunityQuestionStatus::from('intake');
        $this->assertSame(CommunityQuestionStatus::Intake, $status);
    }

    public function testFromStringThrowsOnInvalid(): void
    {
        $this->expectException(\ValueError::class);
        CommunityQuestionStatus::from('invalid_status');
    }
}
