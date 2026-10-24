<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Community\Automation\CommunityQaAutomationService;
use App\Libraries\Community\CommunityPublicRecordProvisioner;
use App\Libraries\Community\CommunityPublisherFactory;
use App\Libraries\Community\OfficialAnswerPublishingService;
use App\Libraries\Content\Quality\ContentRevetService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;
use Tests\Support\Community\ReceiverLikeCommunityPublisher;

/**
 * reach:content-revet --community fixes live community answers with nobody in
 * the loop, through the community machinery, with fakes only at the Gemini
 * HTTP boundary and the community publisher. The publisher fake keeps state
 * and applies the receiver's rules (idempotency keys kept with a body hash,
 * upserts, the correction contract, 404s) — ReceiverLikeCommunityPublisher:
 *
 *   - a Gemini REJECT takes the answer down reversibly (unpublished on the
 *     site, withdrawn in Reach) and queues a priority re-answer; the routine's
 *     answer (no quota) is published as a correction of the SAME answer;
 *   - a REVISE that carries revised text is published as a correction version
 *     through vet → SystemApprover → publish → verify, at the same answer;
 *   - a REVISE without text keeps the answer live and queues a re-answer; a
 *     re-answer that is then rejected leaves the old answer live;
 *   - a dry run changes nothing but says what it would do;
 *   - a failed take-down is reported as the action and does not stop the run;
 *   - a correction of an answer first published under an older envelope goes
 *     live (it used to 409 on the question upsert's reused idempotency key and
 *     dead-letter: production, 23 Oct, answers 1–6 and 8);
 *   - while a correction is being published the old text stays live, and a
 *     correction that gives up leaves the site untouched and the answer
 *     published in Reach with its previous approved version.
 *
 * @internal
 */
final class CommunityAnswerRevetTest extends ApiTestCase
{
    private const TOKEN = 'test-automation-token-0123456789abcdef';

    private ReceiverLikeCommunityPublisher $publisher;

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

        $this->publisher = new ReceiverLikeCommunityPublisher();
        CommunityPublisherFactory::setOverride($this->publisher);
        $this->gemini   = [];
        $this->vetCalls = 0;
        CommunityQaAutomationService::useVetting($this->vetting());
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

    public function testALiveRejectIsWithdrawnRequeuedAndReAnsweredAtTheSameAnswerWithoutQuota(): void
    {
        $live = $this->publishLiveFaq('faq-itc-blocked', 'Which input tax credits are permanently blocked under GST?');
        ReachConfig::set('automation.community.daily_limit', 1); // the quota is now used up

        $this->vettingSays('reject', ['Treats Section 17(5) as a generic term rather than the CGST Act section that blocks these credits.']);
        $report = $this->revet()->run(['types' => [], 'community' => true]);

        $row = $report['items'][0];
        $this->assertSame('fail', $row['decision']);
        $this->assertSame('withdrawn+requeued', $row['action'], json_encode($row));
        $this->assertSame(1, $this->getAuto('v1/automation/community/pending')['body']['data']['reanswer_queue']);
        $this->assertSame(1, $report['totals']['action:withdrawn+requeued']);

        // Down on aicountly.com (reversible unpublish) and withdrawn in Reach, history kept.
        $this->assertCount(1, $this->publisher->getCallsFor('unpublishAnswer'));
        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('withdrawn', $answer['status']);
        $this->assertSame('withdrawn', $answer['publication_status']);
        $this->assertSame('withdrawn', $answer['withdrawal_state']);
        $this->assertSame(1, $this->db()->table('reach_community_answer_versions')->where('answer_id', $answer['id'])->countAllResults());

        $reanswer = $this->db()->table('reach_community_reanswers')->where('answer_id', $answer['id'])->get()->getRowArray();
        $this->assertSame('queued', $reanswer['status']);
        $this->assertSame('withdrawn', $reanswer['mode']);
        $check = $this->db()->table('reach_content_quality_checks')->where('subject_type', 'community_answer')->get()->getRowArray();
        $this->assertSame('withdrawn+requeued', $check['action']);
        $this->assertSame((int) $check['id'], (int) $reanswer['quality_check_id']);

        // The routine sees it first, as a priority item, even with no quota left.
        $pending = $this->getAuto('v1/automation/community/pending?limit=10');
        $this->assertSame(200, $pending['status']);
        $this->assertSame(0, $pending['body']['data']['max_this_run']);
        $item = $pending['body']['data']['questions'][0];
        $this->assertTrue($item['reanswer']);
        $this->assertSame($live['question_id'], $item['question_id']);
        $this->assertSame('withdrawn', $item['replaces']['state']);
        $this->assertStringContainsString('Section 17(5)', $item['why'][0]);
        $this->assertSame(1, $this->service()->plan()['reanswer_queue']);

        // Again: an open re-answer is not vetted twice.
        $this->assertSame(['checked' => 0], $this->revet()->run(['types' => [], 'community' => true])['totals']);

        // The routine answers it through the ordinary endpoint; Gemini approves.
        $this->vettingSays('approve', ['Correct: names Section 17(5) of the CGST Act.']);
        $res = $this->postAuto('v1/automation/community/pending/' . $live['question_id'] . '/answer', [
            'answer_html' => '<p>Section 17(5) of the CGST Act blocks input tax credit on items such as motor vehicles for personal use, food and beverages, club memberships and works contracts for immovable property, with limited exceptions.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'run-reanswer'],
        ]);
        $this->assertSame(201, $res['status'], json_encode($res['body']));
        $this->assertSame('published', $res['body']['data']['outcome']);
        $this->assertSame($live['answer_id'], $res['body']['data']['answer_id'], 'the same answer, not a new one');
        $this->assertSame('revet', $res['body']['data']['origin']);

        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('published', $answer['status']);
        $this->assertSame('published', $answer['publication_status']);
        $this->assertSame('none', $answer['withdrawal_state']);
        $this->assertSame('corrected', $answer['correction_state']);
        $this->assertSame($live['public_url'], $answer['public_url'], 'republished at the same URL');
        $version = $this->db()->table('reach_community_answer_versions')
            ->where('answer_id', $answer['id'])->where('version_number', $answer['approved_version'])->get()->getRowArray();
        $this->assertSame('correction', $version['creation_reason']);
        $this->assertStringContainsString('CGST Act', $version['content']);
        $this->assertSame('live', $this->db()->table('reach_community_reanswers')->where('id', $reanswer['id'])->get()->getRow()->status);

        // What aicountly.com received: the corrected text through the correction
        // contract (revision kept, note shown), then put back up — still
        // AI-assisted with the desk disclosure.
        $this->assertCount(1, $this->publisher->getCallsFor('applyCorrection'));
        $this->assertStringContainsString('CGST Act', (string) $this->publisher->liveBody($live['answer_id']));
        $this->assertSame('Updated for accuracy.', $this->publisher->answers[$live['answer_id']]['correction_note']);
        $publish = $this->publisher->getCallsFor('publishAnswer');
        $this->assertCount(2, $publish);
        $this->assertSame($live['answer_id'], $publish[1]['args'][0]);
        $this->assertStringContainsString('CGST Act', $publish[1]['args'][1]['payload']['body']);
        $this->assertTrue($publish[1]['args'][1]['payload']['ai_assisted']);
        $this->assertFalse($publish[1]['args'][1]['payload']['human_reviewed']);
        $this->assertSame(CommunityPublicRecordProvisioner::DESK_DISCLOSURE, $publish[1]['args'][1]['payload']['ai_disclosure']);

        // No quota used: the plan still reports one routine submission today.
        $this->assertSame(1, $this->service()->plan()['used_today']);
        $this->assertSame([], array_filter($this->service()->plan()['open_questions'], static fn ($q) => $q['reanswer']));
    }

    public function testAReviseWithRevisedTextIsPublishedAsACorrectionVersionOfTheSameAnswer(): void
    {
        $live = $this->publishLiveFaq('faq-3b-late-fee', 'Is there late-fee relief for a delayed GSTR-3B?');

        $revised = '<p>Yes. The late fee for GSTR-3B is capped by turnover: for a nil return it is capped at ₹500 (₹250 CGST + ₹250 SGST), and for other returns caps of ₹2,000 to ₹10,000 apply depending on aggregate turnover. Interest on tax paid late is charged separately.</p>';
        $this->vettingSays('revise', ['Added the late-fee caps.'], [
            'question_title' => 'Is there late-fee relief for a delayed GSTR-3B?', 'question_body' => '',
            'answer_html' => $revised, 'short_answer' => 'Yes — late fees are capped by turnover.',
        ]);
        $this->vettingSays('approve', ['Accurate caps.']);   // the correction, vetted again in the pipeline

        $report = $this->revet()->run(['types' => [], 'community' => true]);
        $this->assertSame('revise', $report['items'][0]['decision']);
        $this->assertSame('corrected', $report['items'][0]['action']);
        $this->assertSame(2 + 1, $this->vetCalls, 'publish + revet + the pipeline\'s own vetting of the correction');

        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('published', $answer['status']);
        $this->assertSame(2, (int) $answer['approved_version']);
        $this->assertSame('corrected', $answer['correction_state']);
        $this->assertSame('Updated for accuracy.', $answer['correction_note']);
        $this->assertSame($live['public_url'], $answer['public_url']);
        $version = $this->db()->table('reach_community_answer_versions')->where('answer_id', $answer['id'])->where('version_number', 2)->get()->getRowArray();
        $this->assertSame('correction', $version['creation_reason']);
        $this->assertStringContainsString('₹500', $version['content']);
        $this->assertStringStartsWith('vetting-revision:', (string) $version['model_route']);

        // Never taken down: the receiver's correction contract swapped the text
        // in one step (old text kept as a revision, note shown, still the
        // desk's AI-assisted answer); identity and question were not re-sent.
        $this->assertFalse($this->publisher->wasCalledWith('unpublishAnswer'));
        $this->assertCount(1, $this->publisher->getCallsFor('publishAnswer'), 'only the first publication');
        $this->assertCount(1, $this->publisher->getCallsFor('createQuestion'), 'only the first publication');
        $correction = $this->publisher->getCallsFor('applyCorrection');
        $this->assertCount(1, $correction);
        $this->assertSame($live['answer_id'], $correction[0]['args'][0]['reach_answer_uuid']);
        $this->assertSame(2, $correction[0]['args'][0]['reach_content_version_number']);
        $this->assertSame($answer['approved_version_checksum'], $correction[0]['args'][0]['payload_checksum']);
        $this->assertSame('Updated for accuracy.', $correction[0]['args'][0]['payload']['correction_note']);
        $this->assertTrue($correction[0]['args'][0]['payload']['ai_assisted']);
        $site = $this->publisher->answers[$live['answer_id']];
        $this->assertSame('published', $site['status']);
        $this->assertTrue($site['ai_assisted']);
        $this->assertStringContainsString('₹500', $site['body']);
        $this->assertStringContainsString('confirm with your CA', $site['revisions'][1], 'the previous text is kept as revision 1');
        $this->assertSame(2, $site['revision']);
        $approval = $this->db()->table('reach_community_answer_approvals')->where('answer_id', $answer['id'])
            ->where('answer_version_number', 2)->get()->getRowArray();
        $this->assertSame('compliance_review', $approval['approval_type']);
        $this->assertSame(SystemApprover::userId(), (int) $approval['approved_by']);

        $sub = $this->db()->table('reach_community_automation_submissions')->where('kind', 'correction')->get()->getRowArray();
        $this->assertSame('revet', $sub['origin']);
        $this->assertSame('published', $sub['status']);
        $this->assertSame('live', $this->db()->table('reach_community_reanswers')->where('answer_id', $answer['id'])->get()->getRow()->status);
        $this->assertSame(1, $this->service()->plan()['used_today'], 'a correction uses no quota');
    }

    public function testAReviseWithoutTextStaysLiveAndAFailedReAnswerLeavesItLive(): void
    {
        $live = $this->publishLiveFaq('faq-advance-tax', 'Do I need to pay advance tax under presumptive taxation?');
        $current = $this->answerRow($live['answer_id']);

        $this->vettingSays('revise', ['Should mention the single instalment by 15 March for 44AD/44ADA.'], []);
        $report = $this->revet()->run(['types' => [], 'community' => true]);
        $this->assertSame('kept_live+requeued', $report['items'][0]['action']);

        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('published', $answer['status']);
        $this->assertSame((int) $current['approved_version'], (int) $answer['approved_version']);
        $this->assertFalse($this->publisher->wasCalledWith('unpublishAnswer'), 'a revise is never taken down');
        $item = $this->service()->plan()['open_questions'][0];
        $this->assertTrue($item['reanswer']);
        $this->assertSame('live', $item['replaces']['state']);

        // The routine's re-answer is rejected: the old answer is put back, live, and offered again.
        $this->vettingSays('reject', ['Wrong due date.']);
        $view = $this->service()->submitMemberAnswer([
            'question_id' => $live['question_id'],
            'answer_html' => '<p>Under Section 44AD you pay all advance tax in one instalment by 31 March of the year.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame('withdrawn', $view['outcome']);
        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('published', $answer['status']);
        $this->assertSame('published', $answer['publication_status']);
        $this->assertSame((int) $current['approved_version'], (int) $answer['approved_version']);
        $this->assertSame((int) $current['current_version'], (int) $answer['current_version']);
        $this->assertSame($current['approved_version_checksum'], $answer['approved_version_checksum']);
        $this->assertCount(1, $this->publisher->getCallsFor('publishAnswer'), 'the rejected text never reached the site');
        $row = $this->db()->table('reach_community_reanswers')->where('answer_id', $answer['id'])->get()->getRowArray();
        $this->assertSame('queued', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertContains('Wrong due date.', json_decode($row['reasons'], true));
        $this->assertSame('published', $this->db()->table('reach_community_questions')->where('uuid', $live['question_id'])->get()->getRow()->handled_by);
    }

    public function testADryRunChangesNothingButSaysWhatItWouldDo(): void
    {
        $a = $this->publishLiveFaq('faq-dry-1', 'Which input tax credits are blocked under GST?');
        $b = $this->publishLiveFaq('faq-dry-2', 'Is there late-fee relief for GSTR-3B filers?');
        $c = $this->publishLiveFaq('faq-dry-3', 'Is a GST invoice needed for a nil-rated supply?');
        $publishes = count($this->publisher->getCallsFor('publishAnswer'));

        $this->vettingSays('reject', ['Materially wrong.']);
        $this->vettingSays('revise', ['Added the caps.'], ['answer_html' => '<p>Yes — the GSTR-3B late fee is capped by turnover, and interest is charged separately.</p>', 'short_answer' => 'Capped by turnover.']);
        $this->vettingSays('approve', ['Fine.']);

        $report = $this->revet()->run(['types' => [], 'community' => true, 'dry_run' => true]);
        $would  = array_column($report['items'], 'would', 'id');
        $ids    = array_map(fn (array $l) => (int) $this->answerRow($l['answer_id'])['id'], [$a, $b, $c]);
        $this->assertSame('withdraw+requeue', $would[$ids[0]]);
        $this->assertSame('correct', $would[$ids[1]]);
        $this->assertSame('none', $would[$ids[2]]);
        $this->assertSame(['none'], array_values(array_unique(array_column($report['items'], 'action'))));
        $this->assertSame(1, $report['totals']['would:withdraw+requeue']);
        $this->assertSame(1, $report['totals']['would:correct']);

        foreach ([$a, $b, $c] as $l) {
            $this->assertSame('published', $this->answerRow($l['answer_id'])['status']);
        }
        $this->assertFalse($this->publisher->wasCalledWith('unpublishAnswer'));
        $this->assertCount($publishes, $this->publisher->getCallsFor('publishAnswer'));
        $this->assertSame(0, $this->db()->table('reach_community_reanswers')->countAllResults());
        $this->assertSame(0, $this->db()->table('reach_community_automation_submissions')->where('origin', 'revet')->countAllResults());
        $this->assertSame(3, $this->db()->table('reach_content_quality_checks')->where('dry_run', true)->where('action', 'none')->countAllResults());
    }

    public function testAFailedTakeDownIsReportedAndTheRunGoesOn(): void
    {
        $a = $this->publishLiveFaq('faq-fail-1', 'Which input tax credits are blocked for a trader?');
        $b = $this->publishLiveFaq('faq-fail-2', 'Do I need to pay advance tax on presumptive income?');
        $this->publisher->failNext('unpublishAnswer', 'network_error', 'Could not reach the public site.');

        $this->vettingSays('reject', ['Materially wrong.']);
        $this->vettingSays('revise', ['Mention 15 March.'], []);
        $report = $this->revet()->run(['types' => [], 'community' => true]);

        $this->assertSame(['withdraw_failed', 'kept_live+requeued'], array_column($report['items'], 'action'));
        $this->assertStringContainsString('did not unpublish', implode(' ', $report['items'][0]['reasons']));
        $this->assertSame('published', $this->answerRow($a['answer_id'])['status'], 'nothing changes in Reach unless the site confirms');
        $this->assertNull($this->db()->table('reach_community_reanswers')->where('answer_id', $this->answerRow($a['answer_id'])['id'])->get()->getRowArray());
        $this->assertSame('withdraw_failed', $this->db()->table('reach_content_quality_checks')->where('subject_id', $this->answerRow($a['answer_id'])['id'])->get()->getRow()->action);
    }

    /**
     * Production, 23 Oct: seven corrections approved by Gemini all dead-lettered
     * with "Public question provisioning failed …: Idempotency key was already
     * used with a different request body. (version_conflict)". The answers had
     * first been published before the question envelope gained the desk
     * disclosure, so the receiver still held "reach-question-<uuid>" with the
     * older body. Reproduced here by leaving those keys on the fake site.
     */
    public function testACorrectionOfAnAnswerFirstPublishedUnderAnOlderEnvelopeGoesLive(): void
    {
        $live     = $this->publishLiveFaq('faq-legacy-envelope', 'Is there late-fee relief for a delayed GSTR-1?');
        $answer   = $this->answerRow($live['answer_id']);
        $identity = $this->db()->table('reach_community_official_identities')->where('id', $answer['identity_id'])->get()->getRow()->uuid;
        foreach (['reach-question-' . $live['question_id'], 'reach-identity-' . $identity, 'reach-answer-v2-' . $live['answer_id']] as $key) {
            $this->publisher->rememberKey($key);
        }
        $before = (string) $this->publisher->liveBody($live['answer_id']);

        $revised = '<p>Yes. The GSTR-1 late fee is ₹50 a day (₹20 for a nil return), capped by turnover under the late-fee waiver notifications; the return must still be filed.</p>';
        $this->vettingSays('revise', ['Add the late-fee amounts.'], ['answer_html' => $revised, 'short_answer' => 'Yes — capped by turnover.']);
        $this->vettingSays('approve', ['Accurate.']);
        $report = $this->revet()->run(['types' => [], 'community' => true]);

        $this->assertSame('corrected', $report['items'][0]['action'], json_encode($report['items'][0]));
        $this->assertStringContainsString('₹50 a day', (string) $this->publisher->liveBody($live['answer_id']));
        $this->assertNotSame($before, $this->publisher->liveBody($live['answer_id']));
        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('published', $answer['status']);
        $this->assertSame(2, (int) $answer['approved_version']);
        $deployment = $this->db()->table('reach_community_deployments')->where('answer_id', $answer['id'])
            ->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('succeeded', $deployment['status']);
        $this->assertSame($live['public_url'], $deployment['public_url']);
        $this->assertNotEmpty($answer['public_external_id']);
        $this->assertSame('published', $this->db()->table('reach_community_automation_submissions')->where('kind', 'correction')->get()->getRow()->status);

        // Nothing was sent under a key the site had already seen with another body.
        foreach ($this->publisher->getCalls() as $call) {
            $this->assertNotSame('version_conflict', $call['response']['error_category'] ?? null, $call['method']);
        }
    }

    /**
     * The first-publication path re-sends identity and question upserts; a
     * record that changed since (a renamed desk, an edited or re-categorised
     * question) must reach the site, and an unchanged one must replay.
     */
    public function testRepublishingAfterTheQuestionChangedUpdatesItInsteadOfReusingTheKey(): void
    {
        $live   = $this->publishLiveFaq('faq-retitle', 'Is a GST invoice needed for exempt supplies?');
        $answer = $this->answerRow($live['answer_id']);
        $this->db()->table('reach_community_questions')->where('uuid', $live['question_id'])
            ->update(['title' => 'Do I issue a bill of supply for exempt supplies under GST?']);

        $provisioner = new CommunityPublicRecordProvisioner($this->publisher);
        $version     = $this->db()->table('reach_community_answer_versions')->where('answer_id', $answer['id'])
            ->where('version_number', $answer['approved_version'])->get()->getRowArray();
        $provisioner->ensureAnswerExists($answer, $version);   // used to throw: 409 on reach-question-<uuid>
        $this->assertSame('Do I issue a bill of supply for exempt supplies under GST?', $this->publisher->questions[$live['question_id']]['title']);

        $keys = count($this->publisher->seenKeys());
        $provisioner->ensureAnswerExists($answer, $version);   // nothing changed: same keys, replayed
        $this->assertCount($keys, $this->publisher->seenKeys());
        $this->assertTrue($this->publisher->getCallsFor('createQuestion')[2]['response']['idempotent_replay'] ?? false);
        foreach ($this->publisher->seenKeys() as $key) {
            $this->assertLessThanOrEqual(64, strlen($key));
        }
    }

    public function testWhileACorrectionIsPublishedTheOldTextStaysLiveAndGivingUpRestoresTheAnswer(): void
    {
        $live    = $this->publishLiveFaq('faq-giveup', 'Do I need to pay advance tax on presumptive business income?');
        $current = $this->answerRow($live['answer_id']);
        $oldText = (string) $this->publisher->liveBody($live['answer_id']);
        $this->publisher->failNext('applyCorrection', 'server_error', 'Internal error on the public site.');

        $this->vettingSays('revise', ['Name the 15 March single instalment.'], [
            'answer_html'  => '<p>Under Sections 44AD and 44ADA the whole advance tax is paid in one instalment by 15 March of the financial year; anything paid by 31 March counts as advance tax too.</p>',
            'short_answer' => 'One instalment by 15 March.',
        ]);
        $this->vettingSays('approve', ['Accurate.']);
        $report = $this->revet()->run(['types' => [], 'community' => true]);
        $this->assertSame('correction_pending', $report['items'][0]['action'], json_encode($report['items'][0]));

        // Approved in Reach, publication retrying — and the reader still sees the old text.
        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('verification_failed', $answer['status']);
        $this->assertSame($oldText, $this->publisher->liveBody($live['answer_id']));
        $this->assertCount(1, $this->publisher->getCallsFor('createAnswer'), 'the live answer is not rewritten by an upsert');

        // The retry sweep gives up after five attempts …
        $svc = new OfficialAnswerPublishingService();
        for ($i = 0; $i < 10; $i++) {
            $deployment = $this->db()->table('reach_community_deployments')->where('answer_id', $answer['id'])
                ->orderBy('id', 'DESC')->get()->getRowArray();
            if ($deployment['status'] === 'dead_letter') {
                break;
            }
            $svc->retryDeployment($deployment);
            $this->assertSame($oldText, $this->publisher->liveBody($live['answer_id']));
        }
        $this->assertSame('dead_letter', $deployment['status']);
        $this->assertSame(5, (int) $deployment['attempt_count']);

        // … and community:schedule's sweep closes the submission and puts the answer back.
        $sub = $this->db()->table('reach_community_automation_submissions')->where('kind', 'correction')->get()->getRowArray();
        $this->db()->table('reach_community_automation_submissions')->where('id', $sub['id'])->update(['next_retry_at' => null]);
        $this->service()->sweep();
        $sub = $this->db()->table('reach_community_automation_submissions')->where('id', $sub['id'])->get()->getRowArray();
        $this->assertSame('failed', $sub['status']);
        $this->assertStringContainsString('Publication gave up', (string) $sub['last_error']);

        $answer = $this->answerRow($live['answer_id']);
        $this->assertSame('published', $answer['status']);
        $this->assertSame('published', $answer['publication_status']);
        $this->assertSame((int) $current['approved_version'], (int) $answer['approved_version']);
        $this->assertSame($current['approved_version_checksum'], $answer['approved_version_checksum']);
        $this->assertSame((int) $current['current_version'], (int) $answer['current_version']);
        $this->assertSame($current['human_reviewed'], $answer['human_reviewed']);
        $this->assertSame($oldText, $this->publisher->liveBody($live['answer_id']), 'never unpublished, never rewritten');
        $this->assertFalse($this->publisher->wasCalledWith('unpublishAnswer'));
        $this->assertSame('queued', $this->db()->table('reach_community_reanswers')->where('answer_id', $answer['id'])->get()->getRow()->status);

        // A stray retry of that deployment cannot publish the abandoned text over the restored version.
        $this->db()->table('reach_community_deployments')->where('id', $deployment['id'])->update(['status' => 'retrying', 'attempt_count' => 1]);
        $this->publisher->clearFailures();
        $retry = $svc->retryDeployment($this->db()->table('reach_community_deployments')->where('id', $deployment['id'])->get()->getRowArray());
        $this->assertSame('superseded', $retry['outcome']);
        $this->assertSame($oldText, $this->publisher->liveBody($live['answer_id']));
        $this->assertSame('published', $this->answerRow($live['answer_id'])['status']);
    }

    /**
     * community:revet-repair on the state production was left in: the answer
     * back to published with its previous version, the re-answer request
     * queued with "Replacement failed: Publication gave up …".
     */
    public function testTheRepairReleasesWhatAGivenUpCorrectionLeftQueuedSoItCanBeReVetted(): void
    {
        $live = $this->giveUpOnACorrection('faq-repair', 'Is GST payable on advances received for services?');
        $other = $this->publishLiveFaq('faq-repair-untouched', 'Is GST payable on a gift to an employee?');
        $id   = (int) $this->answerRow($live['answer_id'])['id'];
        $repair = new \App\Libraries\Community\Automation\CommunityRevetRepairService(null, $this->publisher);

        // The Job monitor's Retry on that deployment's retry job names the way out.
        $deployment = $this->db()->table('reach_community_deployments')->where('answer_id', $id)->orderBy('id', 'DESC')->get()->getRowArray();
        try {
            (new OfficialAnswerPublishingService())->retryDeployment($deployment);
            $this->fail('a dead-lettered deployment was retried');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("status 'dead_letter' and cannot be retried", $e->getMessage());
            $this->assertStringContainsString("community:revet-repair --answer-ids={$id} --apply", $e->getMessage());
        }

        $this->assertSame([$id], $repair->affectedAnswerIds());
        $dry = $repair->run([], false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(['release'], $dry['items'][0]['actions']);
        $this->assertSame('published', $dry['items'][0]['site']['public_status']);
        $this->assertSame('dead_letter', $dry['items'][0]['deployment']['status']);
        $this->assertSame([$id], $dry['released']);
        $this->assertSame('queued', $this->db()->table('reach_community_reanswers')->where('answer_id', $id)->get()->getRow()->status, 'a dry run changes nothing');

        // A restore the pipeline did not get to is done too — only because the site says it is live.
        $this->db()->table('reach_community_official_answers')->where('id', $id)->update(['status' => 'verification_failed']);
        $this->assertSame(['restore', 'release'], $repair->run([$id], false)['items'][0]['actions']);
        $this->assertSame([], $repair->run([$id], false, false)['items'][0]['actions'], 'without the site\'s word nothing is restored');

        $applied = $repair->run([], true);
        $this->assertSame(['restore', 'release'], $applied['items'][0]['done']);
        $this->assertSame('published', $this->answerRow($live['answer_id'])['status']);
        $this->assertSame('cancelled', $this->db()->table('reach_community_reanswers')->where('answer_id', $id)->get()->getRow()->status);
        $this->assertSame([], $repair->run([], true)['items'][0]['done'], 'idempotent');

        // Re-vetting it now publishes Gemini's revision through the fixed path.
        $this->vettingSays('revise', ['Name the time of supply rule.'], [
            'answer_html'  => '<p>For services, GST is due on an advance when it is received: the time of supply is the earlier of the invoice date or the date of payment, so tax is paid on the advance in that month.</p>',
            'short_answer' => 'Yes — on receipt.',
        ]);
        $this->vettingSays('approve', ['Accurate.']);
        $report = $this->revet()->run(['types' => [], 'community' => true, 'answer_ids' => [$id]]);
        $this->assertCount(1, $report['items']);
        $this->assertSame('corrected', $report['items'][0]['action'], json_encode($report['items'][0]));
        $this->assertStringContainsString('time of supply', (string) $this->publisher->liveBody($live['answer_id']));
        $this->assertStringContainsString('confirm with your CA', (string) $this->publisher->liveBody($other['answer_id']));

        // The old dead-lettered deployment's retry job now says there is nothing left to do.
        try {
            (new OfficialAnswerPublishingService())->retryDeployment($deployment);
            $this->fail('a dead-lettered deployment was retried');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Nothing to redo: answer #{$id} was published since by deployment #", $e->getMessage());
            $this->assertStringNotContainsString('revet-repair', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- helpers

    /** A live FAQ whose correction was approved and then given up at publication (the production state). */
    private function giveUpOnACorrection(string $key, string $title): array
    {
        $live = $this->publishLiveFaq($key, $title);
        $this->publisher->failNext('applyCorrection', 'server_error', 'Internal error on the public site.');
        $this->vettingSays('revise', ['Be specific.'], [
            'answer_html'  => '<p>A specific corrected answer that names the provision, the conditions and the time limit, so a reader can act on it without guessing.</p>',
            'short_answer' => 'Specific.',
        ]);
        $this->vettingSays('approve', ['Accurate.']);
        $this->revet()->run(['types' => [], 'community' => true]);

        $answerId = (int) $this->answerRow($live['answer_id'])['id'];
        $svc      = new OfficialAnswerPublishingService();
        for ($i = 0; $i < 10; $i++) {
            $d = $this->db()->table('reach_community_deployments')->where('answer_id', $answerId)->orderBy('id', 'DESC')->get()->getRowArray();
            if ($d['status'] === 'dead_letter') {
                break;
            }
            $svc->retryDeployment($d);
        }
        $this->db()->table('reach_community_automation_submissions')->where('kind', 'correction')->update(['next_retry_at' => null]);
        $this->service()->sweep();
        $this->publisher->clearFailures();
        $this->assertSame('published', $this->answerRow($live['answer_id'])['status']);

        return $live;
    }


    /** @return array{answer_id: string, question_id: string, public_url: ?string} */
    private function publishLiveFaq(string $key, string $title): array
    {
        $this->vettingSays('approve', ['Accurate.']);
        $view = $this->service()->submitFaq([
            'external_key'      => $key,
            'question_title'    => $title,
            'question_body'     => '',
            'category_slug'     => 'gst',
            'answer_html'       => '<p>The general rule is set out in the GST law; the specific provisions depend on your facts, so confirm with your CA.</p>',
            'short_answer'      => 'It depends on the provision; confirm with your CA.',
            'source_references' => [['title' => 'GST portal', 'url' => 'https://www.gst.gov.in/']],
            'provenance'        => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session-test'],
        ]);
        $this->assertSame('published', $view['outcome'], json_encode($view));

        return ['answer_id' => $view['answer_id'], 'question_id' => $view['question_id'], 'public_url' => $view['public_url']];
    }

    private function revet(): ContentRevetService
    {
        return new ContentRevetService($this->vetting());
    }

    private function service(): CommunityQaAutomationService
    {
        return new CommunityQaAutomationService();
    }

    private function vetting(): ContentVettingService
    {
        return new ContentVettingService(function (): array {
            $this->vetCalls++;

            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
        });
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

    /** @return array{status: int, body: array} */
    private function postAuto(string $path, array $body): array
    {
        $response = $this->withHeaders(['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'])
            ->withBodyFormat('json')->call('POST', $path, $body)->response();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true) ?? []];
    }

    /** @return array{status: int, body: array} */
    private function getAuto(string $path): array
    {
        $response = $this->withHeaders(['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'])
            ->call('GET', $path)->response();

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
