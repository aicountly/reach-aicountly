<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityAnswerStatus;
use PHPUnit\Framework\TestCase;

final class CommunityAnswerStatusTest extends TestCase
{
    public function testIntakeCanTransitionToDraftRequested(): void
    {
        $this->assertTrue(CommunityAnswerStatus::Intake->canTransitionTo(CommunityAnswerStatus::DraftRequested));
    }

    public function testIntakeCanTransitionToArchived(): void
    {
        $this->assertTrue(CommunityAnswerStatus::Intake->canTransitionTo(CommunityAnswerStatus::Archived));
    }

    public function testDraftGeneratedCanTransitionToEditorialReview(): void
    {
        $this->assertTrue(CommunityAnswerStatus::DraftGenerated->canTransitionTo(CommunityAnswerStatus::EditorialReview));
    }

    public function testEditorialReviewCanTransitionToApproved(): void
    {
        $this->assertTrue(CommunityAnswerStatus::EditorialReview->canTransitionTo(CommunityAnswerStatus::Approved));
    }

    public function testApprovedCanTransitionToPublishing(): void
    {
        $this->assertTrue(CommunityAnswerStatus::Approved->canTransitionTo(CommunityAnswerStatus::Publishing));
    }

    public function testPublishedIsPubliclyVisible(): void
    {
        $this->assertTrue(CommunityAnswerStatus::Published->isPubliclyVisible());
    }

    public function testDraftGeneratedIsNotPubliclyVisible(): void
    {
        $this->assertFalse(CommunityAnswerStatus::DraftGenerated->isPubliclyVisible());
    }

    public function testApprovedIsPublishable(): void
    {
        $this->assertTrue(CommunityAnswerStatus::Approved->isPublishable());
    }

    public function testPublishedCanTransitionToWithdrawn(): void
    {
        $this->assertTrue(CommunityAnswerStatus::Published->canTransitionTo(CommunityAnswerStatus::Withdrawn));
    }

    public function testWithdrawnCannotTransitionToPublished(): void
    {
        $this->assertFalse(CommunityAnswerStatus::Withdrawn->canTransitionTo(CommunityAnswerStatus::Published));
    }

    public function testArchivedHasNoValidTransitions(): void
    {
        foreach (CommunityAnswerStatus::cases() as $target) {
            $this->assertFalse(CommunityAnswerStatus::Archived->canTransitionTo($target));
        }
    }

    public function testApprovedRequiresReapprovalOnEdit(): void
    {
        $this->assertTrue(CommunityAnswerStatus::Approved->requiresReapprovalOnEdit());
    }

    public function testIntakeDoesNotRequireReapprovalOnEdit(): void
    {
        $this->assertFalse(CommunityAnswerStatus::Intake->requiresReapprovalOnEdit());
    }

    public function testAllStatusesHaveStringBackingValues(): void
    {
        foreach (CommunityAnswerStatus::cases() as $status) {
            $this->assertIsString($status->value);
            $this->assertNotEmpty($status->value);
        }
    }

    public function testAtLeast20StatusesExist(): void
    {
        $this->assertGreaterThanOrEqual(20, count(CommunityAnswerStatus::cases()));
    }

    public function testFromStringReturnsCorrectEnum(): void
    {
        $status = CommunityAnswerStatus::from('published');
        $this->assertSame(CommunityAnswerStatus::Published, $status);
    }

    public function testFromStringThrowsOnInvalid(): void
    {
        $this->expectException(\ValueError::class);
        CommunityAnswerStatus::from('not_a_real_status');
    }
}
