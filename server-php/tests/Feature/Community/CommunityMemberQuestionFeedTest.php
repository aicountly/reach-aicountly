<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Community\Automation\CommunityInboxAutomationService;
use App\Libraries\Community\Automation\CommunityInboxPolicy;
use App\Libraries\Community\Automation\CommunityMemberQuestionFeedService;
use App\Libraries\Community\Automation\CommunityQaAutomationService;
use App\Libraries\Community\CommunityPublicSitePublisher;
use App\Libraries\Community\CommunityPublisherFactory;
use App\Libraries\Community\MockCommunityPublisher;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Real members' questions on aicountly.com/community reach the bot: pulled
 * from the receiver's signed member-questions listing into normal intake,
 * answered from the routine's pending queue, and published onto the member's
 * own thread. Edits and deletes on the site follow.
 *
 * @internal
 */
final class CommunityMemberQuestionFeedTest extends ApiTestCase
{
    private const TOKEN = 'test-automation-token-0123456789abcdef';

    private MockCommunityPublisher $site;

    /** @var list<array{status: int, body: string}> */
    private array $gemini = [];

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['AI_GEMINI_API_KEY', 'AI_GEMINI_DEFAULT_MODEL', 'REACH_AUTOMATION_TOKEN', 'AICOUNTLY_PUBLIC_SITE_BASE_URL',
            'AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN', 'AICOUNTLY_PUBLIC_SITE_SIGNING_KEY', 'AICOUNTLY_PUBLIC_SITE_KEY_ID'] as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['AI_GEMINI_API_KEY']       = 'test-gemini-key';
        $_ENV['AI_GEMINI_DEFAULT_MODEL'] = 'gemini-test-flash';
        $_ENV['REACH_AUTOMATION_TOKEN']  = self::TOKEN;
        putenv('REACH_AUTOMATION_TOKEN=' . self::TOKEN);
        ReachConfig::flush();
        ReachConfig::set('automation.community.triage', false);

        $this->site = new MockCommunityPublisher();
        CommunityPublisherFactory::setOverride($this->site);

        $this->gemini = [];
        CommunityQaAutomationService::useVetting(new ContentVettingService(function (): array {
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

    public function testAMembersQuestionIsIngestedScreenedQueuedAndAnsweredOnItsOwnThread(): void
    {
        $this->site->putMemberQuestion(['public_question_id' => 501, 'title' => 'Is GST charged on export of services?', 'body' => 'We export software services to a US client.']);
        $this->site->putMemberQuestion(['public_question_id' => 502, 'title' => 'My PAN card number is ABCDE1234F, please check my refund', 'category_slug' => 'income-tax']);
        $this->site->putMemberQuestion(['public_question_id' => 503, 'title' => 'Already answered by staff: how to file GSTR-1?', 'has_official_answer' => true]);

        $summary = $this->sync();
        $this->assertSame(3, $summary['seen']);
        $this->assertSame(2, $summary['ingested']);
        $this->assertSame(1, $summary['skipped'], 'a question staff already answered is left alone');

        $q = $this->siteQuestion(501);
        $this->assertSame('public_submission', $q['source_type']);
        $this->assertSame('501', $q['public_external_id']);
        $this->assertSame('https://aicountly.com/community/question/q-501', $q['public_url']);
        $this->assertNull($q['author_reference'], 'no member identity crosses over');
        $this->assertSame(1, (int) $this->db()->table('reach_community_question_classifications')->where('question_id', $q['id'])->countAllResults(), 'screened like any intake');
        $this->assertSame(CommunityInboxPolicy::QUEUE, $q['handled_by']);

        $private = $this->siteQuestion(502);
        $this->assertSame(CommunityInboxPolicy::CLOSED, $private['handled_by']);
        $this->assertSame('personal_data', $private['close_reason']);

        // The routine sees it as a member's question, with the thread its answer goes to.
        $pending = $this->getAuto('v1/automation/community/pending')['body']['data']['questions'];
        $this->assertSame([$q['uuid']], array_column($pending, 'question_id'));
        $this->assertSame('member', $pending[0]['asked_by']);
        $this->assertSame('https://aicountly.com/community/question/q-501', $pending[0]['thread_url']);

        $this->vettingSays('approve', ['Correct: export of services is zero-rated.']);
        $res = $this->postAuto("v1/automation/community/pending/{$q['uuid']}/answer", [
            'answer_html' => '<p>Export of services is a zero-rated supply: you can export under LUT without paying IGST, or pay IGST and claim a refund. Confirm the place-of-supply facts with your CA.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'run-9'],
        ]);
        $this->assertSame(201, $res['status'], json_encode($res['body']));
        $this->assertSame('published', $res['body']['data']['outcome']);

        // On the member's own thread: no desk question created, the answer names the site post.
        $this->assertFalse($this->site->wasCalledWith('createQuestion'), 'the member\'s question is not re-posted as a desk FAQ');
        $create = $this->site->getCallsFor('createAnswer')[0]['args'][0];
        $this->assertSame(501, $create['public_question_id']);
        $this->assertSame($q['uuid'], $create['reach_question_uuid']);
        $this->assertTrue($create['payload']['ai_assisted']);
        $this->assertNotSame('', $create['payload']['ai_disclosure'], 'the AI-assisted disclosure stays');

        $this->assertSame(CommunityInboxPolicy::PUBLISHED, $this->siteQuestion(501)['handled_by']);

        // Idempotent: the same listing again creates nothing.
        $again = $this->sync(true);
        $this->assertSame(0, $again['ingested']);
        $this->assertSame(2, (int) $this->db()->table('reach_community_questions')->where('source_type', 'public_submission')->countAllResults());
    }

    public function testEditsFollowTheMemberAndDeletesCloseTheQuestionWithTheReason(): void
    {
        $this->site->putMemberQuestion(['public_question_id' => 601, 'title' => 'How do I claim ITC on a car?', 'changed_at' => '2026-10-18 05:00:00']);
        $this->site->putMemberQuestion(['public_question_id' => 602, 'title' => 'Is TDS due on rent paid to a company?', 'category_slug' => 'tds-tcs', 'changed_at' => '2026-10-18 05:00:00']);
        $this->site->putMemberQuestion(['public_question_id' => 603, 'title' => 'Do I need an e-way bill for job work?', 'changed_at' => '2026-10-18 05:00:00']);
        $this->sync();

        // Edited before an answer: Reach follows the member's words and screens them again.
        $this->db()->table('reach_community_questions')->where('id', $this->siteQuestion(601)['id'])->update(['triage_decision' => 'approve']);
        $this->site->putMemberQuestion(['public_question_id' => 601, 'title' => 'How do I claim ITC on a car used for deliveries?', 'changed_at' => '2026-10-18 06:00:00']);

        // Deleted by the member while an answer was waiting for vetting.
        $this->postAuto('v1/automation/community/pending/' . $this->siteQuestion(602)['uuid'] . '/answer', [
            'answer_html' => '<p>Yes — rent paid to a company attracts TDS under Section 194-I once the annual threshold is crossed.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ]); // the fake Gemini is down, so it stays pending
        $this->assertSame(CommunityInboxPolicy::VETTING, $this->siteQuestion(602)['handled_by']);
        $this->site->putMemberQuestion(['public_question_id' => 602, 'title' => 'Is TDS due on rent paid to a company?', 'category_slug' => 'tds-tcs', 'status' => 'deleted', 'changed_at' => '2026-10-18 06:00:00']);

        // Hidden by the site's moderators.
        $this->site->putMemberQuestion(['public_question_id' => 603, 'title' => 'Do I need an e-way bill for job work?', 'status' => 'hidden', 'changed_at' => '2026-10-18 06:00:00']);

        $summary = $this->sync();
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(2, $summary['closed']);

        $edited = $this->siteQuestion(601);
        $this->assertSame('How do I claim ITC on a car used for deliveries?', $edited['title']);
        $this->assertNull($edited['triage_decision'], 'the new words are screened again');
        $this->assertStringContainsString('Edited by the member', (string) $edited['handling_note']);
        $this->assertSame(CommunityInboxPolicy::QUEUE, $edited['handled_by']);

        $deleted = $this->siteQuestion(602);
        $this->assertSame('removed_by_member', $deleted['close_reason']);
        $this->assertSame('archived', $deleted['status']);
        $sub = $this->db()->table('reach_community_automation_submissions')->where('question_id', $deleted['id'])->get()->getRowArray();
        $this->assertSame('withdrawn', $sub['status'], 'the waiting answer is withdrawn, never published');
        $this->assertSame(0, (new CommunityQaAutomationService())->sweep()['retried'], 'nothing left to retry');

        $hidden = $this->siteQuestion(603);
        $this->assertSame('hidden_on_site', $hidden['close_reason']);
        $this->assertStringContainsString('hidden on aicountly.com', (string) $hidden['handling_note']);

        $this->assertSame([$edited['uuid']], array_column((new CommunityInboxAutomationService())->pending(), 'question_id'));
    }

    public function testAnEditAfterTheAnswerIsPublishedIsNotedAndTheAnswerStays(): void
    {
        $this->site->putMemberQuestion(['public_question_id' => 701, 'title' => 'When is GSTR-3B due for monthly filers?', 'changed_at' => '2026-10-18 05:00:00']);
        $this->sync();
        $uuid = $this->siteQuestion(701)['uuid'];

        $this->vettingSays('approve', ['Correct.']);
        $this->postAuto("v1/automation/community/pending/{$uuid}/answer", [
            'answer_html' => '<p>For monthly filers GSTR-3B is generally due on the 20th of the following month.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame(CommunityInboxPolicy::PUBLISHED, $this->siteQuestion(701)['handled_by']);

        $this->site->putMemberQuestion(['public_question_id' => 701, 'title' => 'When is GSTR-3B due for quarterly filers?', 'changed_at' => '2026-10-18 07:00:00']);
        $this->assertSame(1, $this->sync()['noted']);

        $row = $this->siteQuestion(701);
        $this->assertSame('When is GSTR-3B due for monthly filers?', $row['title'], 'the answered question keeps the words it was answered for');
        $this->assertSame(CommunityInboxPolicy::PUBLISHED, $row['handled_by']);
        $this->assertStringContainsString('Edited by the member on aicountly.com', (string) $row['handling_note']);
    }

    public function testThePullIsSignedTheWayTheSiteVerifiesItAndFailsClosed(): void
    {
        $data = tempnam(sys_get_temp_dir(), 'member-questions-');
        file_put_contents($data, json_encode([[
            'public_question_id' => 801, 'title' => 'Can I revise a filed GSTR-1?', 'body' => '', 'category_slug' => 'gst',
            'status' => 'published', 'has_official_answer' => false, 'has_accepted_answer' => false,
            'canonical_url' => 'https://aicountly.com/community/question/revise-gstr-1', 'content_checksum' => str_repeat('a', 64),
            'created_at' => '2026-10-18 05:00:00', 'changed_at' => '2026-10-18 05:00:00', 'tags' => [],
        ]]));

        $port   = 18000 + random_int(0, 999);
        $env    = ['FAKE_SITE_TOKEN' => 'site-token-123', 'FAKE_SITE_KEY' => 'site-signing-key-456', 'FAKE_SITE_DATA' => $data] + getenv();
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/../../_support/Fixtures/fake_aicountly_member_questions.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100000);
            }
            $_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL']      = "http://127.0.0.1:{$port}";
            $_ENV['AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN'] = 'site-token-123';
            $_ENV['AICOUNTLY_PUBLIC_SITE_SIGNING_KEY']   = 'site-signing-key-456';
            $_ENV['AICOUNTLY_PUBLIC_SITE_KEY_ID']        = 'reach-v1';

            $summary = (new CommunityMemberQuestionFeedService(new CommunityPublicSitePublisher()))->sync();
            $this->assertArrayNotHasKey('error', $summary, json_encode($summary));
            $this->assertSame(1, $summary['ingested'], 'the fake site accepted the signature and served the listing');
            $this->assertSame('Can I revise a filed GSTR-1?', $this->siteQuestion(801)['title']);

            // A wrong signing key is refused by the site; nothing is ingested, the cursor stays.
            $_ENV['AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'] = 'wrong-key';
            $refused = (new CommunityMemberQuestionFeedService(new CommunityPublicSitePublisher()))->sync();
            $this->assertArrayHasKey('error', $refused);
            $this->assertSame(0, $refused['ingested']);

            // Not configured at all: fails closed with a reason, no call made.
            unset($_ENV['AICOUNTLY_PUBLIC_SITE_SIGNING_KEY']);
            $off = (new CommunityMemberQuestionFeedService(new CommunityPublicSitePublisher()))->sync();
            $this->assertArrayHasKey('error', $off);
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($data);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function sync(bool $fromStart = false): array
    {
        if ($fromStart) {
            $this->db()->table('reach_settings')->where('key', 'community_member_questions_cursor')->delete();
        }
        return (new CommunityMemberQuestionFeedService($this->site))->sync();
    }

    /** @return array<string, mixed> */
    private function siteQuestion(int $postId): array
    {
        return $this->db()->table('reach_community_questions')
            ->where('external_question_id', CommunityMemberQuestionFeedService::externalIdFor($postId))
            ->get()->getRowArray();
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
