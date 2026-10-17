<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Community\Automation\CommunityLegacyDraftService;
use App\Libraries\Community\Automation\CommunityQaAutomationService;
use App\Libraries\Community\CommunityPublicRecordProvisioner;
use App\Libraries\Community\CommunityPublisherFactory;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\MockCommunityPublisher;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Libraries\Community\OfficialAnswerVersionService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The community-qa routine's path, end to end against the real schema:
 * validate → idempotency → question + desk answer → vetting → approve under
 * the system account → publish (mock community publisher) → verify live.
 *
 * Vetting is a ContentVettingService with a scripted Gemini; its runs are
 * recorded for real, so approvals can name them.
 *
 * @internal
 */
final class CommunityQaAutomationTest extends ApiTestCase
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

    public function testApprovedFaqIsPublishedAsTheDesksOwnPostWithoutClaimingHumanReview(): void
    {
        $this->vettingSays('approve', ['Accurate and concise.']);

        $response = $this->postAuto('v1/automation/community-qa', $this->faq());
        $this->assertSame(201, $response['status'], json_encode($response['body']));
        $data = $response['body']['data'];

        $this->assertSame('created', $data['status']);
        $this->assertSame('published', $data['outcome']);
        $this->assertSame('approve', $data['decision']);
        $this->assertNotEmpty($data['public_url']);
        $this->assertSame(2, $data['risk_tier'], 'a GST question is regulatory (tier 2)');

        $answer = $this->answerRow($data['answer_id']);
        $this->assertSame('published', $answer['status']);
        $this->assertSame('t', $answer['ai_assisted']);
        $this->assertSame('f', $answer['human_reviewed'], 'an automatic approval is not a human review');

        $question = $this->db()->table('reach_community_questions')->where('uuid', $data['question_id'])->get()->getRowArray();
        $this->assertSame('official_question', $question['source_type'], 'a routine question is the desk\'s own FAQ');
        $this->assertSame('aicountly-gst-guide', $this->db()->table('reach_community_official_identities')->where('id', $answer['identity_id'])->get()->getRow()->slug);

        // Tier 2 needs compliance_review at publish; it names the system bot and the vetting run.
        $approval = $this->db()->table('reach_community_answer_approvals')->where('answer_id', $answer['id'])->get()->getRowArray();
        $this->assertSame('compliance_review', $approval['approval_type']);
        $this->assertSame(SystemApprover::userId(), (int) $approval['approved_by']);
        $this->assertSame($data['vetting_runs'][0], (int) $approval['vetting_run_id']);
        $this->assertStringContainsString('No person reviewed', $approval['reason']);

        // What aicountly.com receives: official_bot, AI-assisted, the desk disclosure, no human review.
        $q = $this->publisher->getCallsFor('createQuestion')[0]['args'][0];
        $this->assertSame('official_bot', $q['payload']['author_type']);
        $this->assertTrue($q['payload']['ai_assisted']);
        $this->assertSame('gst', $q['payload']['category_slug']);

        $a = $this->publisher->getCallsFor('createAnswer')[0]['args'][0];
        $this->assertTrue($a['payload']['ai_assisted']);
        $this->assertSame(CommunityPublicRecordProvisioner::DESK_DISCLOSURE, $a['payload']['ai_disclosure']);

        $publish = $this->publisher->getCallsFor('publishAnswer')[0]['args'][1];
        $this->assertTrue($publish['payload']['ai_assisted']);
        $this->assertFalse($publish['payload']['human_reviewed']);
        $this->assertSame(
            'Prepared with AI assistance and automatically checked for accuracy by the AICOUNTLY desk.',
            $publish['payload']['ai_disclosure']
        );

        // Idempotent: the same key returns the first result and creates nothing.
        $again = $this->postAuto('v1/automation/community-qa', $this->faq());
        $this->assertSame(200, $again['status']);
        $this->assertSame('already_submitted', $again['body']['data']['status']);
        $this->assertSame($data['answer_id'], $again['body']['data']['answer_id']);
        $this->assertSame(1, $this->db()->table('reach_community_automation_submissions')->countAllResults());
        $this->assertSame(1, $this->vetCalls);
    }

    public function testRejectedFaqIsWithdrawnWithItsReasonsAndNeverPublished(): void
    {
        $this->vettingSays('reject', ['The ITC claim described is not available under Section 17(5).']);

        $data = $this->service()->submitFaq($this->faq(['external_key' => 'faq-reject-1']));

        $this->assertSame('withdrawn', $data['outcome']);
        $this->assertSame('reject', $data['decision']);
        $this->assertSame(['The ITC claim described is not available under Section 17(5).'], $data['reasons']);
        $this->assertSame('archived', $this->answerRow($data['answer_id'])['status']);
        $this->assertSame('archived', $this->db()->table('reach_community_questions')->where('uuid', $data['question_id'])->get()->getRow()->status);
        $this->assertSame(0, $this->publisher->callCount(), 'nothing reaches the public site');

        // Community → Automated feed shows the decision and why.
        $feed = $this->withHeaders($this->authAs('reach_admin'))->call('GET', 'v1/community/automation/feed')->response();
        $this->assertSame(200, $feed->getStatusCode());
        $body = json_decode((string) $feed->getBody(), true)['data'];
        $this->assertSame(1, $body['summary']['withdrawn']);
        $this->assertSame('withdrawn', $body['items'][0]['outcome']);
        $this->assertSame('reject', $body['items'][0]['decision']);
        $this->assertSame($data['reasons'], $body['items'][0]['reasons']);
        $this->assertSame('AICOUNTLY GST Guide', $body['items'][0]['desk']);
    }

    public function testRevisionIsAppliedAndReVettedOnceBeforePublishing(): void
    {
        $this->vettingSays('revise', ['Corrected the due date.'], [
            'question_title' => 'When is GSTR-1 due for monthly filers?',
            'question_body'  => '',
            'answer_html'    => '<p>GSTR-1 for monthly filers is due on the 11th of the following month.</p>',
            'short_answer'   => 'The 11th of the following month.',
        ]);
        $this->vettingSays('approve', ['Accurate.']);

        $data = $this->service()->submitFaq($this->faq(['external_key' => 'faq-revise-1']));

        $this->assertSame('published', $data['outcome']);
        $this->assertSame('revise', $data['decision']);
        $this->assertCount(2, $data['vetting_runs']);
        $this->assertSame(2, $this->vetCalls);

        $answer  = $this->answerRow($data['answer_id']);
        $version = $this->db()->table('reach_community_answer_versions')
            ->where('answer_id', $answer['id'])->where('version_number', $answer['approved_version'])->get()->getRowArray();
        $this->assertSame(2, (int) $answer['approved_version'], 'the corrected text is a new version');
        $this->assertStringContainsString('11th of the following month', $version['content']);
        $this->assertSame('When is GSTR-1 due for monthly filers?', $this->db()->table('reach_community_questions')->where('uuid', $data['question_id'])->get()->getRow()->title);
        $this->assertStringContainsString('11th of the following month', $this->publisher->getCallsFor('publishAnswer')[0]['args'][1]['payload']['body']);
    }

    public function testASecondRevisionIsNotPublished(): void
    {
        $revised = ['question_title' => 'When is GSTR-1 due each month?', 'question_body' => '', 'answer_html' => '<p>It is due on the 11th of the following month for monthly filers.</p>', 'short_answer' => 'The 11th.'];
        $this->vettingSays('revise', ['First fix.'], $revised);
        $this->vettingSays('revise', ['Still not right.'], $revised);

        $data = $this->service()->submitFaq($this->faq(['external_key' => 'faq-revise-2']));

        $this->assertSame('withdrawn', $data['outcome']);
        $this->assertContains('Still not right.', $data['reasons']);
        $this->assertFalse($this->publisher->wasCalledWith('publishAnswer'));
    }

    public function testUnavailableVettingLeavesItPendingUntilTheSweepPublishesIt(): void
    {
        // No scripted verdict: the fake Gemini answers 503 (twice — the service retries once).
        $data = $this->service()->submitFaq($this->faq(['external_key' => 'faq-pending-1']));

        $this->assertSame('pending', $data['outcome']);
        $this->assertSame('pending', $data['decision']);
        $this->assertSame('draft_generated', $this->answerRow($data['answer_id'])['status']);
        $this->assertSame(0, $this->publisher->callCount(), 'unvetted content is never published');

        $row = $this->db()->table('reach_community_automation_submissions')->where('id', $data['submission_id'])->get()->getRowArray();
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNotNull($row['next_retry_at']);
        $this->assertStringContainsString('HTTP 503', (string) $row['last_error']);

        // Not due yet: the sweep leaves it alone.
        $this->assertSame(0, $this->service()->sweep()['retried']);

        $this->db()->table('reach_community_automation_submissions')->where('id', $data['submission_id'])
            ->update(['next_retry_at' => gmdate('Y-m-d H:i:sP', time() - 60)]);
        $this->vettingSays('approve', ['Accurate.']);

        $sweep = $this->service()->sweep();
        $this->assertSame(1, $sweep['retried']);
        $this->assertSame('published', $sweep['outcomes'][0]['outcome']);
        $this->assertSame('published', $this->answerRow($data['answer_id'])['status']);
    }

    public function testQuotaAndSwitchAreEnforcedOverHttp(): void
    {
        ReachConfig::set('automation.community.daily_limit', 1);
        $this->vettingSays('approve', ['Fine.']);

        $this->assertSame(201, $this->postAuto('v1/automation/community-qa', $this->faq(['external_key' => 'quota-1']))['status']);

        $over = $this->postAuto('v1/automation/community-qa', $this->faq(['external_key' => 'quota-2', 'question_title' => 'How do I file a nil GSTR-3B return?']));
        $this->assertSame(429, $over['status']);
        $this->assertStringContainsString('quota', strtolower(json_encode($over['body'])));

        // The plan tells the routine there is nothing left to do today.
        $plan = $this->getAuto('v1/automation/community-plan');
        $this->assertSame(200, $plan['status']);
        $this->assertSame(0, $plan['body']['data']['remaining_today']);
        $this->assertSame(0, $plan['body']['data']['max_this_run']);
        $this->assertContains('How is GST late fee calculated for GSTR-3B?', $plan['body']['data']['recent_question_titles']);

        ReachConfig::set('automation.community.enabled', false);
        $this->assertSame(503, $this->postAuto('v1/automation/community-qa', $this->faq(['external_key' => 'quota-3']))['status']);

        // Invalid payloads are 422 with a message the routine can act on; no token is 401.
        $bad = $this->postAuto('v1/automation/community-qa', ['external_key' => 'bad-1']);
        $this->assertSame(422, $bad['status']);
        $this->assertSame(401, $this->withHeaders(['Accept' => 'application/json'])->call('GET', 'v1/automation/community-plan')->response()->getStatusCode());
    }

    public function testOpenMemberQuestionsAreAnsweredThroughTheSamePath(): void
    {
        $intake = new CommunityQuestionIntakeService();
        $member = $intake->intake([
            'source_type' => 'manual',
            'title'       => 'Can I take input tax credit on a car bought for my business?',
            'body'        => 'We are a small trading firm.',
            'category'    => 'gst',
        ]);
        // A question still holding an empty draft from the old in-Reach drafting.
        $legacy = $intake->intake(['source_type' => 'manual', 'title' => 'How do I record a petty cash expense?', 'category' => 'accounting']);
        $legacyAnswer = (new OfficialAnswerLifecycleService())->createDraft($legacy['uuid'], 'aicountly-accounting-guide');

        $open = array_column($this->service()->plan()['open_questions'], null, 'question_id');
        $this->assertArrayHasKey($member['uuid'], $open);
        $this->assertSame('member', $open[$member['uuid']]['asked_by']);
        $this->assertArrayHasKey($legacy['uuid'], $open, 'an empty legacy draft does not count as an answer');

        $this->vettingSays('approve', ['Correct general rule with a pointer to a CA.']);
        $response = $this->postAuto('v1/automation/community-answers', [
            'question_id'  => $member['uuid'],
            'answer_html'  => '<p>Generally no — ITC on motor vehicles for carrying fewer than 13 people is blocked under Section 17(5), unless the car is used for specified purposes such as further supply or training. Check your exact use with your CA.</p>',
            'provenance'   => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'run-1'],
        ]);
        $this->assertSame(201, $response['status'], json_encode($response['body']));
        $this->assertSame('published', $response['body']['data']['outcome']);
        $this->assertSame($member['uuid'], $response['body']['data']['question_id']);

        $this->vettingSays('approve', ['Fine.']);
        $takeOver = $this->service()->submitMemberAnswer([
            'question_id' => $legacy['uuid'],
            'answer_html' => '<p>Record it as an expense paid from the petty cash account, with the voucher and receipt attached.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame('published', $takeOver['outcome']);
        $this->assertSame($legacyAnswer['uuid'], $takeOver['answer_id'], 'the routine answer takes over the stranded draft');

        $open = array_column($this->service()->plan()['open_questions'], null, 'question_id');
        $this->assertArrayNotHasKey($member['uuid'], $open);
        $this->assertArrayNotHasKey($legacy['uuid'], $open);

        // A question that already has an answer is refused.
        $dup = $this->postAuto('v1/automation/community-answers', [
            'question_id' => $member['uuid'], 'external_key' => 'member-dup',
            'answer_html' => '<p>Another answer to the same question, which should be refused.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame(409, $dup['status']);
    }

    public function testProhibitedTopicsAreBlockedWithoutVetting(): void
    {
        $data = $this->service()->submitFaq($this->faq([
            'external_key'   => 'faq-blocked-1',
            'question_title' => 'How can I backdate a fake invoice to claim credit?',
        ]));

        $this->assertSame('blocked', $data['outcome']);
        $this->assertSame(0, $this->vetCalls);
        $this->assertSame(0, $this->publisher->callCount());
    }

    public function testValidationNoLongerFailsAnswersTheSanitiserOnlyReformats(): void
    {
        $question  = (new CommunityQuestionIntakeService())->intake(['source_type' => 'manual', 'title' => 'What is a debit note under GST?']);
        $lifecycle = new OfficialAnswerLifecycleService();
        $answer    = $lifecycle->createDraft($question['uuid'], 'aicountly-gst-guide');
        // Unpurified, as the old generator stored it: <br/>, &amp; and a closing tag the purifier rewrites.
        (new OfficialAnswerVersionService())->createVersion((int) $answer['id'], '<p>A debit note raises the value of an invoice already issued &amp; is reported in GSTR-1.<br/>Issue it by the due date.</p>', 'A debit note raises an invoice value.');

        $result = $lifecycle->validate($answer['uuid']);
        $this->assertNotContains('unsafe_html', array_column($result['findings'], 'type'));
        $this->assertFalse($lifecycle->moderate($answer['uuid'])['blocked']);
    }

    public function testLegacyDraftsCanBeListedAndClearedToReopenTheirQuestions(): void
    {
        $question = (new CommunityQuestionIntakeService())->intake(['source_type' => 'manual', 'title' => 'How do I reverse an ITC claim?', 'category' => 'gst']);
        $answer   = (new OfficialAnswerLifecycleService())->createDraft($question['uuid'], 'aicountly-gst-guide');
        (new OfficialAnswerVersionService())->createVersion((int) $answer['id'], '<p>An old generated draft that nobody will ever approve.</p>', 'Old draft.');
        $this->db()->table('reach_community_official_answers')->where('id', $answer['id'])->update(['status' => 'draft_generated']);

        $service = new CommunityLegacyDraftService();
        $this->assertSame([$answer['uuid']], array_column($service->list(), 'answer_id'));
        $this->assertNotContains($question['uuid'], array_column($this->service()->plan()['open_questions'], 'question_id'));

        $dry = $service->clear();
        $this->assertTrue($dry['dry_run']);
        $this->assertSame('draft_generated', $this->answerRow($answer['uuid'])['status']);

        $done = $service->clear(false);
        $this->assertSame(1, $done['cleared']);
        $this->assertSame('archived', $this->answerRow($answer['uuid'])['status']);
        $this->assertSame([], $service->list());
        $this->assertContains($question['uuid'], array_column($this->service()->plan()['open_questions'], 'question_id'));
    }

    // ---------------------------------------------------------------- helpers

    private function service(): CommunityQaAutomationService
    {
        return new CommunityQaAutomationService();
    }

    /** @param array<string, string>|null $final */
    private function vettingSays(string $decision, array $reasons, ?array $final = null): void
    {
        $this->gemini[] = ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'decision'   => $decision,
            'reasons'    => $reasons,
            'scores'     => ['accuracy' => 9, 'clarity' => 9, 'compliance' => 9, 'tone' => 9],
            'final_json' => json_encode($final ?? new \stdClass()),
        ])]]]]]])];
    }

    /** @return array<string, mixed> */
    private function faq(array $overrides = []): array
    {
        return $overrides + [
            'external_key'      => 'faq-gst-late-fee',
            'question_title'    => 'How is GST late fee calculated for GSTR-3B?',
            'question_body'     => 'We missed the due date by a few days.',
            'category_slug'     => 'gst',
            'answer_html'       => '<p>The late fee for GSTR-3B is charged per day of delay, capped by the turnover-based maximum notified for the period. Interest on unpaid tax is charged separately.</p>',
            'short_answer'      => 'A per-day fee up to a turnover-based cap, plus interest on unpaid tax.',
            'source_references' => [['title' => 'GST portal', 'url' => 'https://www.gst.gov.in/']],
            'provenance'        => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session-test'],
        ];
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

    /** @return array<string, mixed> */
    private function answerRow(string $uuid): array
    {
        return $this->db()->table('reach_community_official_answers')->where('uuid', $uuid)->get()->getRowArray();
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect();
    }
}
