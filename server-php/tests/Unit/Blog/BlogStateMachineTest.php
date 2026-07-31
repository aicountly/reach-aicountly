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
}
