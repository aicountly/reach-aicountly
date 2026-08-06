<?php

namespace Tests\Unit\Blog;

use App\Libraries\Blog\BlogStateMachine;
use PHPUnit\Framework\TestCase;

final class BlogStateMachineTest extends TestCase
{
    private BlogStateMachine $sm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sm = new BlogStateMachine();
    }

    public function testLegalTransitionFromIdeaToDraft(): void
    {
        $this->assertTrue($this->sm->canTransition(BlogStateMachine::IDEA, BlogStateMachine::DRAFT));
    }

    public function testIllegalTransitionFromIdeaToPublished(): void
    {
        $this->assertFalse($this->sm->canTransition(BlogStateMachine::IDEA, BlogStateMachine::PUBLISHED));
    }

    public function testApprovedCanMoveToPublishQueued(): void
    {
        $this->assertTrue($this->sm->canTransition(BlogStateMachine::APPROVED, BlogStateMachine::PUBLISH_QUEUED));
    }

    public function testSameStateIsAllowed(): void
    {
        $this->assertTrue($this->sm->canTransition(BlogStateMachine::DRAFT, BlogStateMachine::DRAFT));
    }

    public function testRejectedCanReturnToDraft(): void
    {
        $this->assertTrue($this->sm->canTransition(BlogStateMachine::REJECTED, BlogStateMachine::DRAFT));
    }

    /**
     * The submit endpoint routes blog items through
     * BlogHumanApprovalService::submitForReview(), which drives
     * BlogStateMachine to internal_review. Every state it accepts must be able
     * to get there, or "Submit for Review" dies on an illegal transition.
     */
    public function testEveryBlogSubmitSourceCanReachInternalReview(): void
    {
        $sources = (new \ReflectionClass(\App\Libraries\Blog\BlogHumanApprovalService::class))
            ->getConstant('SUBMITTABLE');

        $this->assertNotEmpty($sources);
        foreach ($sources as $from) {
            $this->assertTrue(
                $this->sm->canTransition($from, BlogStateMachine::INTERNAL_REVIEW),
                "submitForReview() accepts '{$from}' but the blog state machine blocks it from internal_review"
            );
        }
    }

    /**
     * internal_review is what the human approve/reject/request-changes path
     * expects, so submitting must land the item exactly there.
     */
    public function testInternalReviewIsTheHumanResolveState(): void
    {
        $this->assertTrue(
            $this->sm->canTransition(BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::APPROVED)
        );
        $this->assertTrue(
            $this->sm->canTransition(BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::CHANGES_REQUESTED)
        );
        $this->assertTrue(
            $this->sm->canTransition(BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::REJECTED)
        );
    }
}
