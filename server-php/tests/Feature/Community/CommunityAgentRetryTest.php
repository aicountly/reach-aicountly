<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\Community\CommunityAgentWorkSelector;
use App\Libraries\Community\CommunityOperationalAgentService;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Libraries\Community\OfficialAnswerRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * The community agents' failure paths, against the real schema.
 *
 * Every curation died on a CHECK constraint after saving its question, the
 * personal-data screen crashed on the questions it exists to catch, and a
 * failed first draft could never be retried. None of it is visible without a
 * database, which is how all three shipped.
 *
 * @internal
 */
final class CommunityAgentRetryTest extends DatabaseTestCase
{
    private const CURATOR         = 'aicountly-question-curator';
    private const GST_DESK        = 'aicountly-gst-guide';
    private const INCOME_TAX_DESK = 'aicountly-income-tax-desk';

    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Unmocked, generation reaches the real orchestrator and fails for
        // want of a seeded AI route — the failure the retry exists for.
        foreach (['APP_ENV', 'REACH_PUB_COMMUNITY_MOCK', 'REACH_AI_MOCK'] as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        parent::tearDown();
    }

    public function testCurationStoresItsHeuristicClassification(): void
    {
        $result = $this->agent()->dispatch(self::CURATOR, 'curate_question', [
            'title'                => 'How is interest calculated when TDS is deposited late?',
            'body'                 => '1.5% vs 1% confusion',
            'category'             => 'tds-tcs',
            'source_type'          => 'official_question',
            'external_question_id' => 'q-test-tds-interest',
        ], forceWindow: true);

        $this->assertArrayNotHasKey('blocked', $result);
        $this->assertSame('heuristic', $this->classifiedBy((int) $result['question_id']));
        $this->assertSame(1, $this->runCount('curate_question', 'success'));
        $this->assertSame(0, $this->runCount('curate_question', 'failed'));
    }

    public function testManualIntakeClassifiesInline(): void
    {
        $question = $this->intake('Which input tax credits are blocked under Section 17(5)?', 'manual');

        $this->assertSame('heuristic', $this->classifiedBy((int) $question['id']));
    }

    public function testAnUnscreenedQuestionIsHeldBackUntilProcessed(): void
    {
        // Non-manual intake is not processed inline, like a curation that
        // failed after saving its question.
        $question = $this->intake('Is an e-way bill needed below 50,000 rupees?', 'import');
        $selector = new CommunityAgentWorkSelector();

        $this->assertContains((int) $question['id'], $selector->questionsAwaitingProcessing(10));
        $this->assertNotContains((int) $question['id'], $this->candidateIds());

        (new CommunityQuestionIntakeService())->completeProcessing((int) $question['id']);

        $this->assertNotContains((int) $question['id'], $selector->questionsAwaitingProcessing(10));
        $this->assertSame('heuristic', $this->classifiedBy((int) $question['id']));

        $candidate = $this->candidateFor((int) $question['id']);
        $this->assertNotNull($candidate);
        $this->assertNull($candidate['answer_uuid']);
        $this->assertSame(1, $candidate['attempt']);
    }

    public function testPersonalDataFoundByTheScreenKeepsTheQuestionFromTheDesks(): void
    {
        $question = $this->intake('Can I share my PAN card number with the GST officer?', 'import');

        (new CommunityQuestionIntakeService())->completeProcessing((int) $question['id']);

        $row = $this->db()->table('reach_community_questions')->where('id', $question['id'])->get()->getRowArray();
        $this->assertSame('t', $row['personal_data_detected']);
        $this->assertSame('{personal_data}', $row['sensitivity_flags']);
        $this->assertNotContains((int) $question['id'], $this->candidateIds());
    }

    public function testAFailedFirstDraftIsRetriedInPlaceByItsOwnerAfterBackoff(): void
    {
        $question = $this->intake('Is there late-fee relief for two missed GSTR-3B months?', 'manual');
        $this->failFirstDraft($question, self::GST_DESK);

        $answer = $this->answerFor((int) $question['id']);
        $this->assertSame(CommunityAnswerStatus::ValidationFailed->value, $answer['status']);
        $this->assertNull($this->candidateFor((int) $question['id']), 'retried before its backoff elapsed');

        $this->backdateFailures($question['uuid'], 1801);

        $candidate = $this->candidateFor((int) $question['id']);
        $this->assertNotNull($candidate);
        $this->assertSame($answer['uuid'], $candidate['answer_uuid']);
        $this->assertSame(self::GST_DESK, $candidate['owner_slug']);
        $this->assertSame(2, $candidate['attempt']);

        $_ENV['REACH_PUB_COMMUNITY_MOCK'] = '1';
        $result = $this->agent()->dispatch(self::GST_DESK, 'draft_answer', [
            'question_uuid' => $question['uuid'],
            'attempt'       => $candidate['attempt'],
        ], forceWindow: true);

        $this->assertTrue($result['retried']);
        $this->assertSame($answer['uuid'], $result['answer_uuid']);
        $this->assertSame(1, $result['version_number']);

        $answers = $this->db()->table('reach_community_official_answers')->where('question_id', $question['id'])->get()->getResultArray();
        $this->assertCount(1, $answers, 'the retry created a second answer instead of reusing the first');
        $this->assertSame(CommunityAnswerStatus::DraftGenerated->value, $answers[0]['status']);

        $this->assertSame(1, $this->runCount('draft_answer', 'failed'));
        $this->assertSame(1, $this->runCount('draft_answer', 'success'));
        $this->assertNull($this->candidateFor((int) $question['id']));
    }

    public function testOnlyTheOwningDeskMayRetryAnAnswer(): void
    {
        $question = $this->intake('How is advance tax handled under presumptive taxation?', 'manual', 'income-tax');
        $this->failFirstDraft($question, self::GST_DESK);
        $before = $this->answerFor((int) $question['id']);

        $error = null;
        try {
            $this->agent()->dispatch(self::INCOME_TAX_DESK, 'draft_answer', ['question_uuid' => $question['uuid']], forceWindow: true);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        $this->assertStringContainsString('belongs to another official identity', (string) $error);
        $this->assertSame($before, $this->answerFor((int) $question['id']));
    }

    public function testAutomaticRetriesStopOnceTheBudgetIsSpent(): void
    {
        $question = $this->intake('What happens if a director misses the DIR-3 KYC deadline?', 'manual', 'company-law');
        $this->failFirstDraft($question, self::GST_DESK);

        for ($i = 1; $i < CommunityOperationalAgentService::MAX_AUTOMATIC_DRAFT_ATTEMPTS; $i++) {
            $this->db()->query(
                "INSERT INTO reach_community_agent_runs
                        (identity_id, operational_role, action, target_type, target_external_ref, outcome, block_reason, metadata, created_at)
                 SELECT identity_id, operational_role, action, target_type, target_external_ref, outcome, block_reason, metadata, created_at
                   FROM reach_community_agent_runs
                  WHERE target_external_ref = ? AND outcome = 'failed'
                  LIMIT 1",
                [$question['uuid']]
            );
        }
        $this->backdateFailures($question['uuid'], 7 * 86400);

        $work = (new CommunityAgentWorkSelector())->draftCandidates(10);

        $this->assertSame([], $work['candidates']);
        $this->assertSame(1, $work['exhausted']);
    }

    public function testADraftThatProducedContentIsNotRegenerated(): void
    {
        $_ENV['REACH_PUB_COMMUNITY_MOCK'] = '1';
        $question = $this->intake('At what employee count does PF become mandatory?', 'manual', 'payroll-hr');
        $this->agent()->dispatch('aicountly-payroll-desk', 'draft_answer', ['question_uuid' => $question['uuid']], forceWindow: true);

        // Content that failed validation is a human's to fix, not a retry's.
        $answer = $this->answerFor((int) $question['id']);
        (new OfficialAnswerRepository())->transitionStatus(
            (int) $answer['id'],
            CommunityAnswerStatus::DraftGenerated,
            CommunityAnswerStatus::ValidationFailed
        );

        $this->assertNull((new OfficialAnswerLifecycleService())->findRetryableDraft($question['uuid']));
        $this->assertNull($this->candidateFor((int) $question['id']));
    }

    private function failFirstDraft(array $question, string $desk): void
    {
        $error = null;
        try {
            $this->agent()->dispatch($desk, 'draft_answer', ['question_uuid' => $question['uuid']], forceWindow: true);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        $this->assertSame(
            'community_answer_generation_failed',
            $error,
            'Precondition: with no AI route seeded, an unmocked generation must fail.'
        );
    }

    private function intake(string $title, string $sourceType, string $category = 'gst'): array
    {
        return (new CommunityQuestionIntakeService())->intake([
            'source_type' => $sourceType,
            'title'       => $title,
            'body'        => 'Asked on behalf of a small business owner.',
            'category'    => $category,
        ]);
    }

    /** @return list<int> */
    private function candidateIds(): array
    {
        return array_column((new CommunityAgentWorkSelector())->draftCandidates(50)['candidates'], 'question_id');
    }

    private function candidateFor(int $questionId): ?array
    {
        foreach ((new CommunityAgentWorkSelector())->draftCandidates(50)['candidates'] as $candidate) {
            if ($candidate['question_id'] === $questionId) {
                return $candidate;
            }
        }

        return null;
    }

    private function answerFor(int $questionId): array
    {
        return $this->db()->table('reach_community_official_answers')->where('question_id', $questionId)->get()->getRowArray();
    }

    private function classifiedBy(int $questionId): ?string
    {
        $row = $this->db()->table('reach_community_question_classifications')->where('question_id', $questionId)->get()->getRowArray();

        return $row['classified_by'] ?? null;
    }

    private function runCount(string $action, string $outcome): int
    {
        return $this->db()->table('reach_community_agent_runs')
            ->where('action', $action)
            ->where('outcome', $outcome)
            ->countAllResults();
    }

    private function backdateFailures(string $questionUuid, int $seconds): void
    {
        $this->db()->query(
            "UPDATE reach_community_agent_runs
                SET created_at = created_at - (? * INTERVAL '1 second')
              WHERE target_external_ref = ? AND outcome = 'failed'",
            [$seconds, $questionUuid]
        );
    }

    private function agent(): CommunityOperationalAgentService
    {
        return new CommunityOperationalAgentService();
    }

    private function db(): BaseConnection
    {
        return Database::connect();
    }
}
