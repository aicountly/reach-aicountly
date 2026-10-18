<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Community\Automation\CommunityInboxAutomationService;
use App\Libraries\Community\Automation\CommunityInboxPolicy;
use App\Libraries\Community\Automation\CommunityQaAutomationService;
use App\Libraries\Community\CommunityAgentWorkSelector;
use App\Libraries\Community\CommunityPublisherFactory;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\MockCommunityPublisher;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Libraries\Community\OfficialAnswerVersionService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The Question Inbox clears itself: the routine pulls the queue and answers
 * by id (vet → approve under the system bot → publish → the question follows),
 * duplicates of answered questions and questions the desks will not answer are
 * closed with the reason, stuck legacy drafts are handed to the routine, and
 * nothing is retried for ever.
 *
 * @internal
 */
final class CommunityInboxAutomationTest extends ApiTestCase
{
    private const TOKEN = 'test-automation-token-0123456789abcdef';

    private MockCommunityPublisher $publisher;

    /** @var list<array{status: int, body: string}> */
    private array $gemini = [];

    private int $vetCalls = 0;

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['AI_GEMINI_API_KEY', 'AI_GEMINI_DEFAULT_MODEL', 'REACH_AUTOMATION_TOKEN'] as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['AI_GEMINI_API_KEY']       = 'test-gemini-key';
        $_ENV['AI_GEMINI_DEFAULT_MODEL'] = 'gemini-test-flash';
        $_ENV['REACH_AUTOMATION_TOKEN']  = self::TOKEN;
        putenv('REACH_AUTOMATION_TOKEN=' . self::TOKEN);
        ReachConfig::flush();

        $this->publisher = new MockCommunityPublisher();
        CommunityPublisherFactory::setOverride($this->publisher);

        $this->gemini   = [];
        $this->vetCalls = 0;
        CommunityQaAutomationService::useVetting(new ContentVettingService(function (): array {
            $this->vetCalls++;
            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
        }));
    }

    protected function tearDown(): void
    {
        CommunityQaAutomationService::useVetting(null);
        CommunityPublisherFactory::setOverride(null);
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        putenv('REACH_AUTOMATION_TOKEN');
        ReachConfig::flush();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ tests

    public function testPendingListsTheQueueMembersFirstOncePerQuestionAndClosesWhatTheDesksWillNotAnswer(): void
    {
        $member  = $this->member('How do I record a petty cash expense in my books?', 'audit-accounting');
        $twin    = $this->member('how do I record a petty-cash expense in my books', 'audit-accounting');
        $curated = $this->curated('Is an e-way bill needed for intra-state movement below 50,000 rupees?');
        $private = $this->member('My PAN card number is ABCDE1234F, can you check my refund?', 'income-tax');

        $res = $this->getAuto('v1/automation/community/pending?limit=10');
        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $data = $res['body']['data'];

        $this->assertTrue($data['enabled']);
        $this->assertSame(10, $data['remaining_today']);
        $this->assertSame(3, $data['max_this_run']);

        $ids = array_column($data['questions'], 'question_id');
        $this->assertSame([$member['uuid'], $curated['uuid']], $ids, 'members first, one per question text, personal data never offered');
        $this->assertNotContains($twin['uuid'], $ids);
        $this->assertSame('member', $data['questions'][0]['asked_by']);
        $this->assertNotNull($data['questions'][0]['queued_at']);
        $this->assertSame('POST /v1/automation/community/pending/' . $member['uuid'] . '/answer', $data['questions'][0]['answer_endpoint']);

        $closed = $this->question($private['uuid']);
        $this->assertSame('archived', $closed['status']);
        $this->assertSame(CommunityInboxPolicy::CLOSED, $closed['handled_by']);
        $this->assertSame('personal_data', $closed['close_reason']);
        $this->assertNotNull($closed['closed_at']);
        $this->assertSame(CommunityInboxPolicy::QUEUE, $this->question($member['uuid'])['handled_by']);
        $this->assertSame(0, $this->vetCalls, 'reading the queue never calls the vetting model');

        // The routine's plan lists the same queue.
        $this->assertSame($ids, array_column((new CommunityQaAutomationService())->plan()['open_questions'], 'question_id'));
    }

    public function testAnswerByIdIsVettedApprovedPublishedAndTheQuestionFollows(): void
    {
        $member = $this->member('Can I take input tax credit on a laptop bought for the office?', 'gst');
        $this->vettingSays('approve', ['Correct general rule.']);

        $res = $this->postAuto("v1/automation/community/pending/{$member['uuid']}/answer", [
            'answer_html' => '<p>Yes — ITC on a laptop used for the business is generally available, as it is not blocked under Section 17(5). Keep the tax invoice and confirm your specifics with your CA.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'run-7'],
        ]);
        $this->assertSame(201, $res['status'], json_encode($res['body']));
        $data = $res['body']['data'];
        $this->assertSame('published', $data['outcome']);
        $this->assertSame($member['uuid'], $data['question_id']);
        $this->assertNotEmpty($data['public_url']);

        $approval = $this->db()->table('reach_community_answer_approvals a')
            ->join('reach_community_official_answers o', 'o.id = a.answer_id')
            ->where('o.uuid', $data['answer_id'])->get()->getRowArray();
        $this->assertSame(SystemApprover::userId(), (int) $approval['approved_by']);
        $this->assertSame($data['vetting_runs'][0], (int) $approval['vetting_run_id']);

        $question = $this->question($member['uuid']);
        $this->assertSame('published', $question['status'], 'the question no longer sits at draft_requested once answered');
        $this->assertSame(CommunityInboxPolicy::PUBLISHED, $question['handled_by']);
        $this->assertStringContainsString((string) $data['public_url'], (string) $question['handling_note']);

        $this->assertSame([], $this->pendingIds());
        $again = $this->postAuto("v1/automation/community/pending/{$member['uuid']}/answer", [
            'answer_html' => '<p>A second answer to the same question, which must be refused.</p>',
            'external_key' => 'second-answer',
            'provenance'   => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame(409, $again['status']);
        $this->assertSame(1, $this->vetCalls);
    }

    public function testTheRoutinesOwnPublishedFaqClosesTheSameQuestionInTheInboxAsAnswered(): void
    {
        $this->vettingSays('approve', ['Accurate.']);
        $faq = (new CommunityQaAutomationService())->submitFaq([
            'external_key'   => 'faq-gst-registration-turnover',
            'question_title' => 'What turnover limit requires GST registration for a small business?',
            'question_body'  => '',
            'category_slug'  => 'gst',
            'answer_html'    => '<p>Registration is needed once aggregate turnover crosses ₹40 lakh for goods (₹20 lakh for services), lower in special category states.</p>',
            'provenance'     => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame('published', $faq['outcome']);

        // The routine's own FAQ is not left in the inbox as draft_requested.
        $faqQuestion = $this->question($faq['question_id']);
        $this->assertSame('published', $faqQuestion['status']);
        $this->assertSame(CommunityInboxPolicy::PUBLISHED, $faqQuestion['handled_by']);

        $member = $this->member('what turnover limit requires GST registration for a small business', 'gst');
        $summary = (new CommunityInboxAutomationService())->sweep(false);
        $this->assertSame(1, $summary['closed']);

        $dup = $this->question($member['uuid']);
        $this->assertSame('duplicate_merged', $dup['status']);
        $this->assertSame('duplicate', $dup['close_reason']);
        $this->assertSame((int) $faqQuestion['id'], (int) $dup['answered_by_question_id']);
        $this->assertStringContainsString('Already answered', (string) $dup['handling_note']);
        $this->assertSame([], $this->pendingIds());

        // The inbox API shows where it went.
        $list = $this->withHeaders($this->authAs('reach_admin'))
            ->call('GET', 'v1/community/questions', ['handled_by' => 'auto_closed'])->response();
        $rows = json_decode((string) $list->getBody(), true)['data'];
        $this->assertSame([$member['uuid']], array_column($rows, 'uuid'));
        $this->assertSame($faq['question_id'], $rows[0]['answered_by_question_uuid']);
        $this->assertSame($faq['public_url'], $rows[0]['answered_by_public_url']);

        // Answering it anyway is refused, without vetting anything.
        $res = $this->postAuto("v1/automation/community/pending/{$member['uuid']}/answer", [
            'answer_html' => '<p>The threshold is ₹40 lakh for goods and ₹20 lakh for services in most states.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame(409, $res['status']);
        $this->assertSame(1, $this->vetCalls);
    }

    public function testAQuestionTheVettingModelRejectsIsClosedWithItsReasonAndScreenedOnce(): void
    {
        $spam = $this->member('Best crypto signals group, join our telegram for daily profits', 'gst');
        $ok   = $this->member('Which ITC is blocked under section 17(5) for a trader?', 'gst');

        $this->vettingSays('reject', ['Promotional spam for a third party, unrelated to accounting or tax.']);
        $this->vettingSays('approve', ['A genuine GST question.']);

        $summary = (new CommunityInboxAutomationService())->sweep();
        $this->assertSame(2, $summary['triaged']);
        $this->assertSame(1, $summary['triage_closed']);

        $closed = $this->question($spam['uuid']);
        $this->assertSame('archived', $closed['status']);
        $this->assertSame('rejected', $closed['close_reason']);
        $this->assertStringContainsString('Promotional spam', (string) $closed['handling_note']);
        $this->assertSame('approve', $this->question($ok['uuid'])['triage_decision']);
        $this->assertSame([$ok['uuid']], $this->pendingIds());

        // Screened once: further sweeps do not call the model again, and write nothing.
        $again = (new CommunityInboxAutomationService())->sweep();
        $this->assertSame(2, $this->vetCalls);
        $this->assertSame(0, $again['changed']);
    }

    public function testAStuckLegacyDraftIsHandedToTheRoutineAfterStuckHoursIdempotently(): void
    {
        $question = $this->member('How do I reverse an ITC claim in GSTR-3B?', 'gst');
        $answer   = (new OfficialAnswerLifecycleService())->createDraft($question['uuid'], 'aicountly-gst-guide');
        (new OfficialAnswerVersionService())->createVersion((int) $answer['id'], '<p>An old generated draft waiting for a reviewer who never comes.</p>', 'Old draft.');
        $this->db()->table('reach_community_official_answers')->where('id', $answer['id'])->update(['status' => 'professional_review']);
        $this->assertSame('draft_requested', $this->question($question['uuid'])['status']);

        $inbox = new CommunityInboxAutomationService();
        $inbox->sweep(false);
        $row = $this->question($question['uuid']);
        $this->assertSame(CommunityInboxPolicy::QUEUE, $row['handled_by'], 'the routine, not a reviewer, handles it next');
        $this->assertNull($row['routine_queued_at'], 'not offered until the draft has been stuck for stuck_hours');
        $this->assertSame([], $this->pendingIds());

        $this->db()->table('reach_community_official_answers')->where('id', $answer['id'])
            ->update(['updated_at' => gmdate('Y-m-d H:i:sP', time() - 7 * 3600)]);
        $summary = $inbox->sweep(false);
        $this->assertSame(1, $summary['released']);
        $this->assertSame('archived', $this->answerStatus((int) $answer['id']));
        $row = $this->question($question['uuid']);
        $this->assertNotNull($row['routine_queued_at']);
        $this->assertStringContainsString('archived after 6 h', (string) $row['handling_note']);
        $this->assertSame([$question['uuid']], $this->pendingIds());

        $this->assertSame(0, $inbox->sweep(false)['changed'], 'a settled inbox writes nothing');
        $this->assertSame(0, $this->publisher->callCount(), 'nothing public changed');
    }

    public function testNothingIsRetriedForEver(): void
    {
        ReachConfig::set('automation.community.triage', false);
        $member = $this->member('Can a sole proprietor claim depreciation on a car?', 'income-tax');
        $answer = [
            'answer_html' => '<p>Yes, a proprietor can claim depreciation on a car used for the business, in proportion to business use.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ];

        $this->vettingSays('reject', ['The answer misstates the depreciation rate.']);
        $first = $this->postAuto("v1/automation/community/pending/{$member['uuid']}/answer", $answer);
        $this->assertSame('withdrawn', $first['body']['data']['outcome']);
        $this->assertSame([], $this->pendingIds(), 'it rests a day after an answer that was not published');
        $this->assertSame(CommunityInboxPolicy::QUEUE, $this->question($member['uuid'])['handled_by']);

        $this->db()->table('reach_community_automation_submissions')
            ->update(['created_at' => gmdate('Y-m-d H:i:sP', time() - 2 * 86400), 'updated_at' => gmdate('Y-m-d H:i:sP', time() - 2 * 86400)]);
        $this->assertSame([$member['uuid']], $this->pendingIds());

        // The second answer is a new submission, not "already submitted" for ever.
        $this->vettingSays('reject', ['Still inaccurate.']);
        $second = $this->postAuto("v1/automation/community/pending/{$member['uuid']}/answer", $answer);
        $this->assertSame(201, $second['status'], json_encode($second['body']));
        $this->assertSame('member-answer:' . $member['uuid'] . ':2', $second['body']['data']['external_key']);

        $closed = $this->question($member['uuid']);
        $this->assertSame(CommunityInboxPolicy::CLOSED, $closed['handled_by']);
        $this->assertSame('unanswerable', $closed['close_reason']);
        $this->assertStringContainsString('Still inaccurate.', (string) $closed['handling_note']);
        $this->assertSame([], $this->pendingIds());

        // A question whose personal-data screen keeps failing is retried a bounded number of times, then closed.
        $unscreened = (new CommunityQuestionIntakeService())->intake(['source_type' => 'official_question', 'title' => 'How do I set up opening stock in Inventory?']);
        $this->assertContains((int) $unscreened['id'], (new CommunityAgentWorkSelector())->questionsAwaitingProcessing(10));
        $this->db()->table('reach_community_questions')->where('id', $unscreened['id'])->update(['automation_failures' => CommunityInboxPolicy::MAX_AUTOMATION_FAILURES]);
        $this->assertNotContains((int) $unscreened['id'], (new CommunityAgentWorkSelector())->questionsAwaitingProcessing(10));
        (new CommunityInboxAutomationService())->sweep(false);
        $this->assertSame('unscreenable', $this->question($unscreened['uuid'])['close_reason']);

        // Triage that cannot reach the model stops after a bounded number of tries.
        ReachConfig::set('automation.community.triage', true);
        $other = $this->member('Is GST charged on export of services?', 'gst');
        $calls = $this->vetCalls;
        for ($i = 0; $i < 5; $i++) {
            (new CommunityInboxAutomationService())->sweep();
        }
        $this->assertSame(CommunityInboxPolicy::MAX_AUTOMATION_FAILURES, (int) $this->question($other['uuid'])['automation_failures']);
        $this->assertSame($calls + 2 * CommunityInboxPolicy::MAX_AUTOMATION_FAILURES, $this->vetCalls, 'two HTTP tries per screen, three screens, then it stops');
        $this->assertSame([$other['uuid']], $this->pendingIds(), 'still offered to the routine, whose answer is vetted anyway');
    }

    public function testTheRoutineCanDeclineAQuestionItCannotAnswerAndTheBotClosesIt(): void
    {
        $member = $this->member('How do I sync my Tally data into AICOUNTLY Notes?', 'saas-product-help');
        $this->pendingIds();

        $bad = $this->postAuto("v1/automation/community/pending/{$member['uuid']}/decline", ['reason' => 'short']);
        $this->assertSame(422, $bad['status']);

        $res = $this->postAuto("v1/automation/community/pending/{$member['uuid']}/decline", [
            'reason' => 'The Notes app code has no Tally import, so there is no truthful how-to to give.',
        ]);
        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(CommunityInboxPolicy::CLOSED, $res['body']['data']['handled_by']);
        $this->assertSame('declined', $res['body']['data']['close_reason']);
        $this->assertStringContainsString('no Tally import', $res['body']['data']['note']);
        $this->assertSame('archived', $this->question($member['uuid'])['status']);

        $this->assertSame(409, $this->postAuto("v1/automation/community/pending/{$member['uuid']}/decline", [
            'reason' => 'Trying to decline a second time should be refused.',
        ])['status']);
        $kept = $this->question($member['uuid']);
        $this->assertSame(CommunityInboxPolicy::CLOSED, $kept['handled_by'], 'a later refresh keeps the bot close and its reason');
        $this->assertSame('declined', $kept['close_reason']);
        $this->assertStringContainsString('no Tally import', (string) $kept['handling_note']);
        $this->assertSame(404, $this->postAuto('v1/automation/community/pending/00000000-0000-4000-8000-000000000000/decline', [
            'reason' => 'An unknown question cannot be declined.',
        ])['status']);
        $this->assertSame(401, $this->withHeaders(['Accept' => 'application/json'])->call('GET', 'v1/automation/community/pending')->response()->getStatusCode());
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function member(string $title, string $category): array
    {
        return (new CommunityQuestionIntakeService())->intake(['source_type' => 'manual', 'title' => $title, 'category' => $category]);
    }

    /** @return array<string, mixed> */
    private function curated(string $title): array
    {
        $intake   = new CommunityQuestionIntakeService();
        $question = $intake->intake(['source_type' => 'official_question', 'title' => $title, 'category' => 'gst']);
        $intake->completeProcessing((int) $question['id']);
        // Curated seeds are older than today's member questions; order is by priority, not age alone.
        $this->db()->table('reach_community_questions')->where('id', $question['id'])->update(['triage_score' => 99]);
        return $question;
    }

    /** @return list<string> */
    private function pendingIds(): array
    {
        return array_column((new CommunityInboxAutomationService())->pending(20), 'question_id');
    }

    private function vettingSays(string $decision, array $reasons): void
    {
        $this->gemini[] = ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'decision'   => $decision,
            'reasons'    => $reasons,
            'scores'     => ['accuracy' => 9, 'clarity' => 9, 'compliance' => 9, 'tone' => 9],
            'final_json' => json_encode(new \stdClass()),
        ])]]]]]])];
    }

    /** @return array<string, mixed> */
    private function question(string $uuid): array
    {
        return $this->db()->table('reach_community_questions')->where('uuid', $uuid)->get()->getRowArray();
    }

    private function answerStatus(int $id): string
    {
        return (string) $this->db()->table('reach_community_official_answers')->where('id', $id)->get()->getRow()->status;
    }

    /** @return array{status: int, body: array} */
    private function postAuto(string $path, array $body): array
    {
        $response = $this->withHeaders(['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'])
            ->withBodyFormat('json')
            ->call('POST', $path, $body)
            ->response();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true) ?? []];
    }

    /** @return array{status: int, body: array} */
    private function getAuto(string $path): array
    {
        $response = $this->withHeaders(['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'])
            ->call('GET', $path)
            ->response();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true) ?? []];
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect();
    }
}
