<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityQuestionStatus;
use App\Libraries\Community\CommunityQuestionStatusMirror;
use PHPUnit\Framework\TestCase;

/**
 * The mirror is what stops questions from sitting at draft_requested for the
 * rest of their life while their answer is generated, reviewed and published.
 * These tests cover the pure parts: the status mapping and the route finder.
 */
final class CommunityQuestionStatusMirrorTest extends TestCase
{
    public function testEveryAnswerStatusMapsToAQuestionStatus(): void
    {
        foreach (CommunityAnswerStatus::cases() as $answerStatus) {
            $this->assertNotNull(
                CommunityQuestionStatusMirror::questionStatusFor($answerStatus),
                "No question status mirrors answer status {$answerStatus->value}"
            );
        }
    }

    public function testMappingUsesTheSameCaseName(): void
    {
        $this->assertSame(
            CommunityQuestionStatus::Published,
            CommunityQuestionStatusMirror::questionStatusFor(CommunityAnswerStatus::Published)
        );
    }

    public function testDirectTransitionIsASingleHop(): void
    {
        $path = CommunityQuestionStatusMirror::shortestPath(
            CommunityQuestionStatus::DraftRequested,
            CommunityQuestionStatus::Generating
        );

        $this->assertSame([CommunityQuestionStatus::Generating], $path);
    }

    public function testSameStatusNeedsNoHops(): void
    {
        $this->assertSame([], CommunityQuestionStatusMirror::shortestPath(
            CommunityQuestionStatus::Approved,
            CommunityQuestionStatus::Approved
        ));
    }

    public function testIndirectTransitionWalksIntermediateStates(): void
    {
        // publish() moves the answer approved -> publishing -> published; a
        // question sitting at draft_requested has to walk the whole chain.
        $path = CommunityQuestionStatusMirror::shortestPath(
            CommunityQuestionStatus::DraftRequested,
            CommunityQuestionStatus::Published
        );

        $this->assertNotSame([], $path);
        $this->assertSame(CommunityQuestionStatus::Published, end($path));

        $from = CommunityQuestionStatus::DraftRequested;
        foreach ($path as $step) {
            $this->assertTrue(
                $from->canTransitionTo($step),
                "Illegal hop {$from->value} -> {$step->value} in computed path"
            );
            $from = $step;
        }
    }

    public function testUnpublishingIsReachableFromPublished(): void
    {
        $path = CommunityQuestionStatusMirror::shortestPath(
            CommunityQuestionStatus::Published,
            CommunityQuestionStatus::Unpublished
        );

        $this->assertSame(
            [CommunityQuestionStatus::Unpublishing, CommunityQuestionStatus::Unpublished],
            $path
        );
    }

    public function testTerminalStatusHasNoOutboundRoute(): void
    {
        $this->assertSame([], CommunityQuestionStatusMirror::shortestPath(
            CommunityQuestionStatus::Archived,
            CommunityQuestionStatus::Published
        ));
    }

    /**
     * The answer machine allows regeneration out of every pre-approval state.
     * If the question machine did not, a retried draft would strand the
     * question one state behind its own answer.
     */
    public function testRegenerationStatesAreReachable(): void
    {
        foreach ([
            CommunityQuestionStatus::ValidationFailed,
            CommunityQuestionStatus::ChangesRequested,
            CommunityQuestionStatus::DraftGenerated,
        ] as $from) {
            $this->assertTrue(
                $from->canTransitionTo(CommunityQuestionStatus::Generating),
                "{$from->value} cannot follow its answer back into generating"
            );
        }
    }

    /**
     * Every status an answer can reach must be reachable on the question side
     * from the point a draft is requested, or the mirror silently gives up.
     */
    public function testEveryAnswerStatusIsReachableFromDraftRequested(): void
    {
        foreach (CommunityAnswerStatus::cases() as $answerStatus) {
            $target = CommunityQuestionStatusMirror::questionStatusFor($answerStatus);
            if ($target === null || $target === CommunityQuestionStatus::DraftRequested) {
                continue;
            }

            // intake/triaged precede draft_requested and are never mirrored back.
            if (in_array($target, [CommunityQuestionStatus::Intake, CommunityQuestionStatus::Triaged], true)) {
                continue;
            }

            $this->assertNotSame(
                [],
                CommunityQuestionStatusMirror::shortestPath(CommunityQuestionStatus::DraftRequested, $target),
                "No route from draft_requested to {$target->value}"
            );
        }
    }
}
