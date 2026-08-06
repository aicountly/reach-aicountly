<?php

namespace Tests\Unit\Content;

use App\Libraries\ContentWorkflowService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentWorkflowService state-machine logic.
 *
 * These tests do NOT require a database — they test pure transition logic
 * using reflection to access the TRANSITIONS map.
 */
final class ContentWorkflowServiceTest extends TestCase
{
    private array $transitions;

    protected function setUp(): void
    {
        // Extract the private TRANSITIONS constant via reflection
        $ref = new \ReflectionClass(ContentWorkflowService::class);
        $this->transitions = $ref->getConstant('TRANSITIONS');
    }

    public function testIdea_canTransitionToBriefOrArchived(): void
    {
        $allowed = $this->transitions['idea'];
        $this->assertContains('brief', $allowed);
        $this->assertContains('archived', $allowed);
    }

    public function testDraft_canTransitionToValidationPending(): void
    {
        $this->assertContains('validation_pending', $this->transitions['draft']);
    }

    /**
     * submit() accepts draft, validation_pending and changes_requested as sources
     * and transitions to review_pending — every one of those must be a legal edge,
     * otherwise "Submit for Review" fails with "Cannot transition from ...".
     */
    public function testEverySubmitSourceCanReachReviewPending(): void
    {
        foreach (['draft', 'validation_pending', 'changes_requested'] as $from) {
            $this->assertContains(
                'review_pending',
                $this->transitions[$from],
                "submit() accepts '{$from}' but the state machine blocks it from review_pending"
            );
        }
    }

    public function testReviewPending_canTransitionToApprovedOrChangesRequestedOrRejected(): void
    {
        $allowed = $this->transitions['review_pending'];
        $this->assertContains('approved', $allowed);
        $this->assertContains('changes_requested', $allowed);
        $this->assertContains('rejected', $allowed);
    }

    public function testApproved_canTransitionToScheduledOrArchived(): void
    {
        $allowed = $this->transitions['approved'];
        $this->assertContains('scheduled', $allowed);
        $this->assertContains('archived', $allowed);
    }

    /**
     * A successful deployment marks the item published. Every state a
     * deployment can legitimately start from must therefore have an edge into
     * 'published' — without one, a manual "Publish now" left the item stuck on
     * 'approved' even though the article was live on the public site.
     */
    public function testEveryPublishableStateCanReachPublished(): void
    {
        foreach (['approved', 'scheduled', 'ready_for_publication'] as $from) {
            $this->assertContains(
                'published',
                $this->transitions[$from],
                "A deployment can start from '{$from}' but the state machine blocks it from published"
            );
        }
    }

    public function testReadyForPublication_isNotADeadEnd(): void
    {
        $allowed = $this->transitions['ready_for_publication'];
        $this->assertContains('published', $allowed);
        $this->assertContains('archived', $allowed);
    }

    public function testArchived_hasNoForwardTransitions(): void
    {
        $this->assertEmpty($this->transitions['archived']);
    }

    public function testRefreshDue_canGoBackToDraft(): void
    {
        $this->assertContains('draft', $this->transitions['refresh_due']);
    }

    public function testChangesRequested_canGoBackToDraft(): void
    {
        $this->assertContains('draft', $this->transitions['changes_requested']);
    }

    public function testAllStatesAreDefined(): void
    {
        $states = [
            'idea', 'brief', 'draft', 'validation_pending', 'review_pending',
            'changes_requested', 'approved', 'scheduled', 'ready_for_publication',
            'published', 'refresh_due', 'rejected', 'archived',
        ];
        foreach ($states as $state) {
            $this->assertArrayHasKey($state, $this->transitions, "State {$state} missing from TRANSITIONS");
        }
    }

    public function testPublicStagesConstant_HasFourStages(): void
    {
        $this->assertCount(4, ContentWorkflowService::STAGES);
        $this->assertContains('editorial_review', ContentWorkflowService::STAGES);
        $this->assertContains('final_approval', ContentWorkflowService::STAGES);
    }
}
