<?php

declare(strict_types=1);

namespace Tests\Unit\Community;

use App\Enums\CommunityQuestionStatus;
use App\Libraries\Community\Automation\CommunityInboxPolicy as P;
use App\Libraries\Community\Automation\CommunityQaAutomationService;
use PHPUnit\Framework\TestCase;

/**
 * Who handles a Question Inbox row. Every open question must land on the
 * routine queue, vetting/publishing, published or a bot close — never a
 * state that waits for a person.
 *
 * @internal
 */
final class CommunityInboxPolicyTest extends TestCase
{
    private const STUCK_HOURS = 6;

    /** @param array<string, mixed> $overrides */
    private static function facts(array $overrides = []): array
    {
        return $overrides + [
            'status'                 => 'draft_requested',
            'source_type'            => 'manual',
            'moderation_state'       => 'clean',
            'personal_data_detected' => false,
            'spam_score'             => 0.1,
            'classified'             => true,
            'automation_failures'    => 0,
            'triage_decision'        => null,
            'published_answer'       => null,
            'live_answer'            => null,
            'inflight'               => null,
            'failed_submissions'     => 0,
            'blocked_submissions'    => 0,
            'last_reasons'           => [],
            'seconds_since_last_failure' => null,
            'duplicate_of'           => null,
        ];
    }

    private static function decide(array $overrides = []): array
    {
        return P::decide(self::facts($overrides), self::STUCK_HOURS);
    }

    public function testAPublishedAnswerMarksTheQuestionPublishedWhateverElseIsTrue(): void
    {
        $d = self::decide([
            'published_answer' => ['url' => 'https://aicountly.com/community/q/1'],
            'duplicate_of'     => ['id' => 9, 'title' => 'x', 'url' => null],
            'failed_submissions' => 5,
        ]);
        $this->assertSame('publish', $d['action']);
        $this->assertSame(P::PUBLISHED, $d['handled_by']);
        $this->assertStringContainsString('https://aicountly.com/community/q/1', $d['note']);
    }

    public function testAnUnansweredQuestionGoesToTheRoutineQueue(): void
    {
        $d = self::decide();
        $this->assertSame('queue', $d['action']);
        $this->assertSame(P::QUEUE, $d['handled_by']);
        $this->assertSame(P::QUEUED_NOTE, $d['note']);
    }

    public function testAnEmptyLegacyDraftIsTakenOverAtOnce(): void
    {
        foreach (['draft_requested', 'validation_failed'] as $status) {
            $d = self::decide(['live_answer' => ['status' => $status, 'versions' => 0, 'age_seconds' => 60]]);
            $this->assertSame('queue', $d['action'], $status);
        }
    }

    public function testAStuckLegacyDraftWaitsThenIsReleasedToTheRoutine(): void
    {
        $fresh = self::decide(['live_answer' => ['status' => 'draft_generated', 'versions' => 1, 'age_seconds' => 3600]]);
        $this->assertSame('wait', $fresh['action']);
        $this->assertSame(P::QUEUE, $fresh['handled_by'], 'the routine, not a person, gets it next');

        foreach (['draft_generated', 'editorial_review', 'professional_review', 'generating', 'changes_requested'] as $status) {
            $old = self::decide(['live_answer' => ['status' => $status, 'versions' => 1, 'age_seconds' => self::STUCK_HOURS * 3600]]);
            $this->assertSame('release', $old['action'], $status);
            $this->assertStringContainsString('archived after 6 h', $old['note']);
        }
    }

    public function testAnswersInFlightAreLabelledNotQueued(): void
    {
        $this->assertSame(P::VETTING, self::decide(['inflight' => ['status' => 'pending']])['handled_by']);
        $this->assertSame(P::PUBLISHING, self::decide(['inflight' => ['status' => 'approved']])['handled_by']);
        $this->assertSame(P::PUBLISHING, self::decide(['live_answer' => ['status' => 'verification_failed', 'versions' => 1, 'age_seconds' => 999999]])['handled_by']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function closes(): iterable
    {
        yield 'personal data'  => [['personal_data_detected' => true], 'personal_data'];
        yield 'moderation'     => [['moderation_state' => 'flagged'], 'moderation'];
        yield 'spam'           => [['spam_score' => 0.9], 'spam'];
        yield 'prohibited'     => [['blocked_submissions' => 1], 'prohibited'];
        yield 'triage reject'  => [['triage_decision' => 'reject'], 'rejected'];
        yield 'two failures'   => [['failed_submissions' => 2, 'last_reasons' => ['Wrong section cited.']], 'unanswerable'];
        yield 'duplicate'      => [['duplicate_of' => ['id' => 3, 'title' => 'What is GST?', 'url' => 'https://aicountly.com/c/3']], 'duplicate'];
        yield 'unscreenable'   => [['classified' => false, 'automation_failures' => 3], 'unscreenable'];
    }

    /** @dataProvider closes */
    public function testQuestionsTheDesksWillNotAnswerAreClosedWithTheReason(array $facts, string $reason): void
    {
        $d = self::decide($facts);
        $this->assertSame('close', $d['action']);
        $this->assertSame(P::CLOSED, $d['handled_by']);
        $this->assertSame($reason, $d['close_reason']);
        $this->assertNotSame('', trim($d['note']));
    }

    public function testCloseNotesCarryTheirEvidence(): void
    {
        $this->assertStringContainsString('Wrong section cited.', self::decide(['failed_submissions' => 2, 'last_reasons' => ['Wrong section cited.']])['note']);
        $this->assertStringContainsString('https://aicountly.com/c/3', self::decide(['duplicate_of' => ['id' => 3, 'title' => 'What is GST?', 'url' => 'https://aicountly.com/c/3']])['note']);
    }

    public function testOneFailedAnswerRestsThenIsOfferedAgain(): void
    {
        $d = self::decide(['failed_submissions' => 1, 'seconds_since_last_failure' => 3600]);
        $this->assertSame('queue', $d['action']);
        $this->assertStringContainsString('offered again', $d['note']);
    }

    public function testAnUnscreenedQuestionWaitsForItsScreenABoundedNumberOfTimes(): void
    {
        $this->assertSame('wait', self::decide(['classified' => false, 'automation_failures' => 2])['action']);
        $this->assertSame('close', self::decide(['classified' => false, 'automation_failures' => 3])['action']);
    }

    public function testClosedQuestionsAreLabelledByWhoClosedThem(): void
    {
        $byBot = self::decide(['status' => 'archived', 'failed_submissions' => 1, 'last_reasons' => ['Not accurate.']]);
        $this->assertSame('label_closed', $byBot['action']);
        $this->assertSame(P::CLOSED, $byBot['handled_by']);
        $this->assertStringContainsString('Not accurate.', $byBot['note']);

        $this->assertSame(P::CLOSED_BY_PERSON, self::decide(['status' => 'archived'])['handled_by']);
    }

    public function testNoDecisionEverWaitsOnAPerson(): void
    {
        $samples = [
            [], ['inflight' => ['status' => 'pending']], ['status' => 'intake'], ['status' => 'duplicate_merged'],
            ['live_answer' => ['status' => 'professional_review', 'versions' => 2, 'age_seconds' => 10]],
            ['live_answer' => ['status' => 'approved', 'versions' => 2, 'age_seconds' => 10]],
        ];
        foreach ($samples as $facts) {
            $this->assertContains(self::decide($facts)['handled_by'], P::HANDLERS);
        }
    }

    public function testQuestionsCanFollowTheirAnswerOrBeMergedFromDraftRequested(): void
    {
        $this->assertTrue(CommunityQuestionStatus::DraftRequested->canTransitionTo(CommunityQuestionStatus::Published));
        $this->assertTrue(CommunityQuestionStatus::DraftRequested->canTransitionTo(CommunityQuestionStatus::DuplicateMerged));
        // A fresh question reaches published through draft_requested, never directly.
        $this->assertFalse(CommunityQuestionStatus::Intake->canTransitionTo(CommunityQuestionStatus::Published));
        $this->assertCount(3, CommunityQaAutomationService::path(
            CommunityQuestionStatus::Intake,
            CommunityQuestionStatus::Published,
            [CommunityQuestionStatus::class, 'validTransitions']
        ));
    }
}
