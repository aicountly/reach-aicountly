<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Enums\VideoProjectStatus;
use App\Enums\VideoScriptWorkflowStatus;
use App\Libraries\Video\VideoWorkflowService;
use App\Libraries\Video\VideoProjectRepository;
use App\Libraries\Video\VideoScriptRepository;
use App\Libraries\Video\VideoScriptVersionService;
use App\Libraries\Video\VideoLifecycleValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Video\VideoWorkflowService
 */
class VideoWorkflowServiceTest extends CIUnitTestCase
{
    public function testSubmitTransitionsDraftToInReview(): void
    {
        $validator = new VideoLifecycleValidator();
        $this->assertTrue(
            $validator->canScriptTransition(VideoScriptWorkflowStatus::Draft, VideoScriptWorkflowStatus::InReview),
            'Draft → InReview must be a valid script transition via enum helper'
        );
        // Also verify string-based method
        $this->assertTrue(
            $validator->canTransitionScript(VideoScriptWorkflowStatus::Draft->value, VideoScriptWorkflowStatus::InReview->value),
            'Draft → InReview must be a valid script transition via string helper'
        );
    }

    public function testApproveTransitionsInReviewToApproved(): void
    {
        $validator = new VideoLifecycleValidator();
        $this->assertTrue(
            $validator->canScriptTransition(VideoScriptWorkflowStatus::InReview, VideoScriptWorkflowStatus::Approved),
            'InReview → Approved must be a valid script transition'
        );
    }

    public function testRejectTransitionsInReviewToRejected(): void
    {
        $validator = new VideoLifecycleValidator();
        $this->assertTrue(
            $validator->canScriptTransition(VideoScriptWorkflowStatus::InReview, VideoScriptWorkflowStatus::Rejected),
            'InReview → Rejected must be a valid script transition'
        );
    }

    public function testRequestChangesTransitionsInReviewToChangesRequested(): void
    {
        $validator = new VideoLifecycleValidator();
        $this->assertTrue(
            $validator->canScriptTransition(
                VideoScriptWorkflowStatus::InReview,
                VideoScriptWorkflowStatus::ChangesRequested
            ),
            'InReview → ChangesRequested must be a valid script transition'
        );
    }

    public function testCannotApproveFromDraftDirectly(): void
    {
        $validator = new VideoLifecycleValidator();
        $this->assertFalse(
            $validator->canScriptTransition(VideoScriptWorkflowStatus::Draft, VideoScriptWorkflowStatus::Approved),
            'Draft → Approved must NOT be a direct valid transition'
        );
    }

    public function testApprovedScriptProjectStatusLeadsToRenderQueued(): void
    {
        $validator = new VideoLifecycleValidator();
        $this->assertTrue(
            $validator->canProjectTransition(
                VideoProjectStatus::ScriptApproved,
                VideoProjectStatus::RenderQueued
            ),
            'ScriptApproved → RenderQueued must be a valid project transition via enum helper'
        );
        // Also verify string-based method
        $this->assertTrue(
            $validator->canTransitionProject(
                VideoProjectStatus::ScriptApproved->value,
                VideoProjectStatus::RenderQueued->value
            ),
            'ScriptApproved → RenderQueued must be a valid project transition via string helper'
        );
    }

    public function testVersionImmutabilityRejectsDuplicateApproval(): void
    {
        $mockRepo  = $this->createMock(\App\Libraries\Video\VideoScriptRepository::class);
        $mockRepo->method('getVersionById')->willReturn([
            'id'          => 1,
            'script_id'   => 10,
            'approved_by' => 99,
            'approved_at' => '2026-07-14 00:00:00',
        ]);

        $service = new \App\Libraries\Video\VideoScriptVersionService($mockRepo);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already approved');
        $service->stampApproval(1, 5);
    }

    public function testVersionBelongsToScriptValidation(): void
    {
        $mockRepo = $this->createMock(\App\Libraries\Video\VideoScriptRepository::class);
        $mockRepo->method('getVersionById')->willReturn([
            'id'        => 5,
            'script_id' => 999,
        ]);

        $service = new \App\Libraries\Video\VideoScriptVersionService($mockRepo);

        $this->expectException(\InvalidArgumentException::class);
        $service->assertVersionBelongsToScript(5, 1);
    }
}
