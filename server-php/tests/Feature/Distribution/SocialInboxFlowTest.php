<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient;
use App\Libraries\Distribution\SocialInbox\FlowTicketClient;
use App\Libraries\Distribution\SocialInbox\SocialInboxClassifier;
use App\Libraries\Distribution\SocialInbox\SocialInboxService;
use App\Libraries\Reports\DailyReportBuilder;
use App\Libraries\Reports\DailyReportRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * The social inbox bot against the real schema, with social-api.ai, Gemini
 * (classifier + vetting) and Flow all scripted: nothing is reported as
 * replied unless social-api.ai accepted the reply, complaints become Flow
 * tickets exactly once, and every row ends with an honest status and reason.
 *
 * @covers \App\Libraries\Distribution\SocialInbox\SocialInboxService
 * @covers \App\Jobs\SocialInboxPollJob
 *
 * @internal
 */
final class SocialInboxFlowTest extends DatabaseTestCase
{
    use VideoEnv;

    private const KEY      = 'sapi_key_inbox_feature_secret_77';
    private const FLOW     = 'https://flow.aicountly.org/api';
    private const FLOW_KEY = 'flow-reach-key-feature-0123456789abcdef';

    private FakeHttpTransport $http;
    private int $now;
    /** @var array<string, array<string, mixed>> text fragment → classifier verdict */
    private array $verdicts = [];
    /** @var list<array<string, mixed>> */
    private array $alerts = [];
    private string $vetDecision = 'approve';
    private ?array $vetRevision = null;
    private bool $classifierDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv([
            'SOCIALAPI_API_KEY' => self::KEY, 'SOCIALAPI_BASE_URL' => null, 'SOCIALAPI_ACCOUNT_LINKEDIN' => null,
            'AI_GEMINI_API_KEY' => 'gem-feature-key-123456', 'AI_GEMINI_DEFAULT_MODEL' => 'gemini-2.5-flash', 'AI_GEMINI_BASE_URL' => null,
            'SOCIAL_INBOX_ENABLED' => null, 'SOCIAL_INBOX_AUTO_REPLY' => null, 'ENGAGE_API_BASE_URL' => null, 'ENGAGE_INBOUND_TOKEN' => null,
        ]);
        SocialApiAccounts::forget();
        $this->now      = time();
        $this->alerts   = [];
        $this->verdicts = [
            'how do i'   => ['category' => 'question', 'confidence' => 92, 'severity' => 'low', 'personal_data' => false, 'summary' => 'Asks how to file GSTR-1.', 'reply' => 'You can file GSTR-1 from Returns in AICOUNTLY. For your own case, check with your CA.'],
            'love it'    => ['category' => 'praise', 'confidence' => 95, 'severity' => 'low', 'personal_data' => false, 'summary' => 'Praise.', 'reply' => 'Thank you so much!'],
            'broken'     => ['category' => 'complaint', 'confidence' => 90, 'severity' => 'medium', 'personal_data' => false, 'summary' => 'Export broken.', 'reply' => ''],
            'lawyer'     => ['category' => 'complaint', 'confidence' => 88, 'severity' => 'high', 'severity_reason' => 'legal threat', 'personal_data' => false, 'summary' => 'Threatens legal action over billing.', 'reply' => ''],
            'buy now'    => ['category' => 'spam_abuse', 'confidence' => 99, 'severity' => 'low', 'personal_data' => false, 'summary' => 'Spam.', 'reply' => ''],
            'pricing'    => ['category' => 'sales_lead', 'confidence' => 91, 'severity' => 'low', 'personal_data' => false, 'summary' => 'Asks for pricing for 20 users.', 'reply' => 'Thanks for your interest! Please send us a direct message or visit aicountly.com.'],
            'maybe'      => ['category' => 'question', 'confidence' => 40, 'severity' => 'low', 'personal_data' => false, 'summary' => 'Unclear.', 'reply' => 'Happy to help.'],
            'my pan'     => ['category' => 'sensitive', 'confidence' => 93, 'severity' => 'medium', 'personal_data' => true, 'summary' => 'Shares a PAN.', 'reply' => ''],
        ];
        $this->vetDecision    = 'approve';
        $this->vetRevision    = null;
        $this->classifierDown = false;
        $this->http = (new FakeHttpTransport())->on('GET', '#/v1/accounts$#', FakeHttpTransport::json(200, [
            ['id' => 'acc_li', 'name' => 'AICOUNTLY', 'platform' => 'linkedin', 'status' => 'active'],
        ]));
    }

    protected function tearDown(): void
    {
        SocialApiAccounts::forget();
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function service(?string $flowKey = self::FLOW_KEY): SocialInboxService
    {
        $client = new SocialApiAiClient(self::KEY, null, $this->http, static function (int $s): void {
        });
        $classifier = new SocialInboxClassifier(function (string $url, array $body): array {
            if ($this->classifierDown) {
                return ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
            }
            $text = strtolower((string) $body['contents'][0]['parts'][0]['text']);
            foreach ($this->verdicts as $needle => $v) {
                if (str_contains($text, $needle)) {
                    return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($v)]]]]]])];
                }
            }
            return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['category' => 'other', 'confidence' => 80, 'severity' => 'low', 'personal_data' => false, 'summary' => '', 'reply' => ''])]]]]]])];
        });
        $vetting = new ContentVettingService(function (string $url, array $body): array {
            $draft = json_decode((string) preg_replace('/^.*DRAFT TO VET \(JSON\):\n(.*?)\n\nReturn your verdict.*$/s', '$1', $body['contents'][0]['parts'][0]['text']), true);
            $final = $this->vetRevision ?? $draft;
            $verdict = ['decision' => $this->vetDecision, 'reasons' => $this->vetDecision === 'reject' ? ['A public reply would be risky.'] : ['Polite and accurate.'], 'scores' => ['tone' => 9], 'final_json' => json_encode($final)];
            return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($verdict)]]]]]])];
        });

        return new SocialInboxService(
            null,
            $client,
            new SocialApiAccounts($client, null, fn (): int => $this->now, false),
            $classifier,
            $vetting,
            new FlowTicketClient(self::FLOW, $flowKey, $this->http),
            null,
            function (array $row): array {
                $this->alerts[] = $row;
                return ['sent' => true, 'reason' => null];
            },
            fn (): int => $this->now,
        );
    }

    private function inbox(array $items): void
    {
        // Confirmed live shapes: comments and direct messages are separate lists; an empty one is data: null.
        $this->http->on('GET', '#/v1/inbox/comments\?#', FakeHttpTransport::json(200, ['data' => $items, 'pagination' => ['has_more' => false]]));
        $this->http->on('GET', '#/v1/inbox/conversations\?#', FakeHttpTransport::json(200, ['data' => null, 'pagination' => ['has_more' => false, 'next_cursor' => ''], 'sync_state' => 'idle', 'last_synced_at' => null]));
    }

    private function item(string $id, string $text, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'type' => 'comment', 'platform' => 'linkedin', 'account_id' => 'acc_li', 'text' => $text,
            'author' => ['name' => 'Asha Verma', 'username' => 'asha_' . $id], 'post_id' => 'post_1',
            'post_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1', 'created_at' => gmdate('c', $this->now - 600),
        ];
    }

    private function acceptReplies(): void
    {
        $this->http->on('POST', '#/v1/inbox/comments/[^/]+/reply$#', static fn (string $m, string $url): \App\Libraries\Video\Automation\Http\HttpResponse
            => FakeHttpTransport::json(201, ['data' => ['id' => 'rep_' . basename(dirname($url))]]));
    }

    private function acceptFlow(string $number = 'TCK-2026-00042'): void
    {
        $this->http->on('POST', '#^https://flow\.aicountly\.org/api/internal/reach/social-tickets$#', FakeHttpTransport::json(201, [
            'success' => true, 'data' => ['created' => true, 'ticket_id' => 42, 'ticket_number' => $number, 'priority' => 'high', 'status' => 'open'],
        ]));
    }

    private function row(string $externalId): array
    {
        return $this->db->table('reach_social_inbox')->where('external_id', $externalId)->get()->getRowArray();
    }

    // ── poll ────────────────────────────────────────────────────────────────

    public function testPollStoresEachMessageOnceAndNeverOurOwnReplies(): void
    {
        $this->inbox([
            $this->item('c1', 'How do I file GSTR-1?'),
            $this->item('c2', 'Love it'),
            $this->item('c3', 'Thanks for asking!', ['direction' => 'outbound']),
        ]);
        $svc = $this->service();
        $first = $svc->poll();
        $this->assertSame('ok', $first['status']);
        $this->assertSame(2, $first['stored']);
        $this->assertSame(1, $first['own']);
        $this->assertSame(0, $svc->poll()['stored'], 'polling again stores nothing new');
        $this->assertSame(2, $this->db->table('reach_social_inbox')->countAllResults());

        $calls = $this->http->to('#/v1/inbox/comments\?#');
        $this->assertStringContainsString('account_id=acc_li', $calls[0]['url']);
        $this->assertStringContainsString('since=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $this->now - 24 * 3600)), $calls[0]['url'], 'first read: lookback');
        $this->assertStringContainsString('since=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $this->now - 600 - 3600)), $calls[1]['url'], 'later reads: newest stored minus an hour');
        $this->assertStringContainsString('skipped', $first['accounts']['facebook'], 'networks without an account are skipped, not failed');

        $r = $this->row('c1');
        $this->assertSame(['linkedin', 'acc_li', 'comment', 'post_1', 'asha_c1', 'new'], [$r['network'], $r['account_id'], $r['kind'], $r['thread_id'], $r['author_handle'], $r['status']]);
        $this->assertStringNotContainsString(self::KEY, (string) $r['raw_json']);
    }

    public function testA404OnTheAssumedInboxPathIsReportedWithTheSettingToFix(): void
    {
        $this->http->on('GET', '#/v1/inbox/(comments|conversations)\?#', FakeHttpTransport::json(404, ['error' => ['code' => 'resource.not_found']]));
        $r = $this->service()->poll();
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('social.inbox.list_path', $r['errors']['linkedin']);
        $this->assertCount(1, $this->http->to('#/v1/inbox/comments\?#'), 'a 404 path is not asked again for the next account');
        $this->assertCount(1, $this->http->to('#/v1/inbox/conversations\?#'));
    }

    // ── replies ─────────────────────────────────────────────────────────────

    public function testAQuestionIsAnsweredOnlyAfterVettingAndSocialApiAcceptingIt(): void
    {
        $this->inbox([$this->item('c1', 'How do I file GSTR-1 in AICOUNTLY?')]);
        $this->acceptReplies();
        $this->vetDecision = 'revise';
        $this->vetRevision = ['reply' => 'You can file GSTR-1 from Returns in AICOUNTLY — check the specifics with your CA.'];
        $out = $this->service()->run();

        $this->assertSame(1, $out['process']['replied']);
        $r = $this->row('c1');
        $this->assertSame(['replied', 'sent', 'question', 'revise'], [$r['status'], $r['reply_status'], $r['classification'], $r['vetting_decision']]);
        $this->assertSame('You can file GSTR-1 from Returns in AICOUNTLY — check the specifics with your CA. — AICOUNTLY team', $r['reply_text'], 'the vetted text, signed');
        $this->assertSame('rep_c1', $r['reply_external_id']);
        $posted = json_decode((string) $this->http->to('#/reply$#', 'POST')[0]['body'], true);
        $this->assertSame($r['reply_text'], $posted['text']);
    }

    public function testARejectedDraftIsNeverPosted(): void
    {
        $this->inbox([$this->item('c1', 'Love it')]);
        $this->acceptReplies();
        $this->vetDecision = 'reject';
        $this->service()->run();

        $r = $this->row('c1');
        $this->assertSame(['ignored', 'skipped'], [$r['status'], $r['reply_status']]);
        $this->assertStringContainsString('Vetting rejected the reply: A public reply would be risky.', $r['status_reason']);
        $this->assertSame([], $this->http->to('#/reply$#'));
    }

    public function testAReplySocialApiRefusesIsFailedNotReplied(): void
    {
        $this->inbox([$this->item('c1', 'Love it')]);
        $this->http->on('POST', '#/reply$#', FakeHttpTransport::json(422, ['error' => ['code' => 'platform.comment_closed', 'message' => 'Comments are closed on this post']]));
        $this->service()->run();

        $r = $this->row('c1');
        $this->assertSame(['failed', 'failed'], [$r['status'], $r['reply_status']]);
        $this->assertNull($r['replied_at']);
        $this->assertStringContainsString('Comments are closed', $r['status_reason']);
    }

    // ── complaints ──────────────────────────────────────────────────────────

    public function testAComplaintBecomesOneFlowTicketAndIsAcknowledgedWithItsNumber(): void
    {
        $this->inbox([$this->item('c9', 'Your GST export has been broken for a week')]);
        $this->acceptFlow();
        $this->acceptReplies();
        $this->service()->run();

        $r = $this->row('c9');
        $this->assertSame(['ticket_created', 'TCK-2026-00042', 'sent'], [$r['status'], $r['ticket_number'], $r['reply_status']]);
        $this->assertStringContainsString('logged it as TCK-2026-00042', (string) $r['reply_text']);
        $this->assertStringContainsString('direct message', (string) $r['reply_text']);
        $this->assertStringContainsString('Lodged in Flow as TCK-2026-00042. Acknowledged publicly.', (string) $r['status_reason']);

        $flow = $this->http->to('#social-tickets$#', 'POST');
        $this->assertCount(1, $flow);
        $body = json_decode((string) $flow[0]['body'], true);
        $this->assertSame(['c9', 'linkedin', 'asha_c9', 'medium', 'post_1'], [$body['source_message_id'], $body['network'], $body['author_handle'], $body['severity'], $body['thread_id']]);
        $this->assertSame('Bearer ' . self::FLOW_KEY, $flow[0]['headers']['Authorization']);
        $this->assertSame([], $this->alerts, 'a medium complaint is only in the daily report');

        $this->service()->run();
        $this->assertCount(1, $this->http->to('#social-tickets$#', 'POST'), 'never lodged twice');
    }

    public function testAHighSeverityComplaintAlertsTheOwnerOnce(): void
    {
        $this->inbox([$this->item('c5', 'My lawyer will hear about this billing')]);
        $this->acceptFlow('TCK-2026-00077');
        $this->acceptReplies();
        $this->service()->run();
        $this->service()->run();

        $this->assertCount(1, $this->alerts);
        $this->assertSame('TCK-2026-00077', $this->alerts[0]['ticket_number']);
        $this->assertNotNull($this->row('c5')['owner_alerted_at']);
        $this->assertSame('high', json_decode((string) $this->http->to('#social-tickets$#')[0]['body'], true)['severity']);
    }

    public function testAComplaintWaitsWhileFlowIsNotSetUpAndIsLodgedOnceItIs(): void
    {
        $this->inbox([$this->item('c9', 'Export broken again')]);
        $this->acceptReplies();
        $this->service(null)->run();

        $r = $this->row('c9');
        $this->assertSame('deferred', $r['status']);
        $this->assertNull($r['ticket_number']);
        $this->assertSame('none', $r['reply_status'], 'no acknowledgement without a ticket');
        $this->assertStringContainsString('key is not set', (string) $r['status_reason']);
        $this->assertSame([], $this->http->to('#/reply$#'));

        $this->acceptFlow();
        $this->service()->process();
        $r = $this->row('c9');
        $this->assertSame(['ticket_created', 'TCK-2026-00042', 'sent'], [$r['status'], $r['ticket_number'], $r['reply_status']]);
    }

    public function testFlowOutagesAreRetriedThenGivenUpHonestly(): void
    {
        $this->inbox([$this->item('c9', 'Export broken')]);
        $this->http->on('POST', '#social-tickets$#', FakeHttpTransport::json(502, ['message' => 'Bad gateway']));
        $svc = $this->service();
        $svc->run();
        $this->assertSame('retry', $this->row('c9')['status']);
        for ($i = 0; $i < SocialInboxService::MAX_ATTEMPTS; $i++) {
            $svc->process();
        }
        $r = $this->row('c9');
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('Gave up after 5 attempts: Flow unavailable', (string) $r['status_reason']);
        $this->assertCount(5, $this->http->to('#social-tickets$#'));
    }

    // ── spam, leads, sensitive ──────────────────────────────────────────────

    public function testSpamIsNeverAnsweredAndHiddenOnlyWhenSocialApiAccepts(): void
    {
        $this->inbox([$this->item('s1', 'BUY NOW cheap followers'), $this->item('s2', 'buy now crypto')]);
        $this->acceptReplies();
        $svc = $this->service();
        $svc->poll();
        $svc->processOne((int) $this->row('s1')['id']);
        $this->assertSame('ignored', $this->row('s1')['status']);
        $this->assertStringContainsString('hiding is off', (string) $this->row('s1')['status_reason']);

        \App\Libraries\Settings\ReachConfig::set('social.inbox.hide_spam', true);
        $this->http->on('POST', '#/v1/inbox/comments/s2/hide$#', FakeHttpTransport::json(200, ['ok' => true]));
        $svc->processOne((int) $this->row('s2')['id']);
        $this->assertSame('hidden', $this->row('s2')['status']);
        $this->assertSame([], $this->http->to('#/reply$#'), 'spam is never replied to');
    }

    public function testASalesEnquiryBecomesALeadAndGetsAVettedReply(): void
    {
        $this->inbox([$this->item('l1', 'What is your pricing for 20 users?')]);
        $this->acceptReplies();
        $this->service()->run();

        $r = $this->row('l1');
        $this->assertSame(['lead_created', 'sent'], [$r['status'], $r['reply_status']]);
        $lead = $this->db->table('reach_leads')->where('id', $r['lead_id'])->get()->getRowArray();
        $this->assertSame(['social_inbox', 'linkedin', 'social_inbox', 'high'], [$lead['source_kind'], $lead['utm_source'], $lead['utm_medium'], $lead['priority']]);
        $this->assertStringContainsString('pricing for 20 users', (string) $lead['notes']);
        $this->assertStringContainsString('Recorded as lead #' . $r['lead_id'] . ' (Engage: retry_scheduled)', (string) $r['status_reason'], 'Engage not configured here — said so, not hidden');
    }

    public function testPersonalDataGetsTheFixedPrivacyNudgeNeverAnEcho(): void
    {
        $this->inbox([$this->item('p1', 'my PAN is ABCDE1234F please check')]);
        $this->acceptReplies();
        $this->service()->run();
        $r = $this->row('p1');
        $this->assertSame('replied', $r['status']);
        $this->assertStringContainsString('do not share personal or account details in public', (string) $r['reply_text']);
        $this->assertStringNotContainsString('ABCDE1234F', (string) $r['reply_text']);
        $this->assertSame('t', $r['personal_data']);
    }

    // ── guards ──────────────────────────────────────────────────────────────

    public function testGuardsCapThreadConfidenceAndAge(): void
    {
        \App\Libraries\Settings\ReachConfig::set('social.inbox.daily_reply_cap', 2);
        $this->inbox([
            $this->item('g1', 'How do I add a user?', ['created_at' => gmdate('c', $this->now - 900)]),
            $this->item('g2', 'How do I export?', ['author' => ['username' => 'asha_g1'], 'created_at' => gmdate('c', $this->now - 800)]), // same author + thread as g1
            $this->item('g3', 'maybe something', ['created_at' => gmdate('c', $this->now - 700)]),
            $this->item('g4', 'How do I file?', ['created_at' => gmdate('c', $this->now - 72 * 3600)]),
            $this->item('g5', 'Love it', ['created_at' => gmdate('c', $this->now - 600)]),
            $this->item('g6', 'Love it too', ['created_at' => gmdate('c', $this->now - 500)]),
        ]);
        $this->acceptReplies();
        $this->service()->run();

        $this->assertSame('replied', $this->row('g1')['status']);
        $this->assertSame('ignored', $this->row('g2')['status']);
        $this->assertStringContainsString('one reply per thread', (string) $this->row('g2')['status_reason']);
        $this->assertStringContainsString('below the 70% floor', (string) $this->row('g3')['status_reason']);
        $this->assertStringContainsString('too late to answer', (string) $this->row('g4')['status_reason']);
        $this->assertSame('replied', $this->row('g5')['status']);
        $this->assertSame(['deferred', 'pending'], [$this->row('g6')['status'], $this->row('g6')['reply_status']]);
        $this->assertStringContainsString('Daily reply cap (2) reached', (string) $this->row('g6')['status_reason']);
        $this->assertCount(2, $this->http->to('#/reply$#'), 'exactly the cap');
    }

    public function testAutoReplyOffStillClassifiesAndLodges(): void
    {
        $this->setVideoEnv(['SOCIAL_INBOX_AUTO_REPLY' => 'false']);
        $this->inbox([$this->item('a1', 'How do I add a user?'), $this->item('a2', 'Export broken')]);
        $this->acceptFlow();
        $this->service()->run();
        $this->assertSame('ignored', $this->row('a1')['status']);
        $this->assertStringContainsString('Auto-reply is off', (string) $this->row('a1')['status_reason']);
        $this->assertSame(['ticket_created', 'skipped'], [$this->row('a2')['status'], $this->row('a2')['reply_status']]);
        $this->assertSame([], $this->http->to('#/reply$#'));
    }

    public function testAClassifierOutageIsRetriedThenFailed(): void
    {
        $this->inbox([$this->item('c1', 'How do I file GSTR-1?')]);
        $this->classifierDown = true;
        $svc = $this->service();
        $svc->run();
        $r = $this->row('c1');
        $this->assertSame(['retry', 1], [$r['status'], (int) $r['attempts']]);
        $this->assertStringContainsString('Classification unavailable: Classifier model returned HTTP 503', (string) $r['last_error']);
        for ($i = 0; $i < 5; $i++) {
            $svc->process();
        }
        $this->assertSame('failed', $this->row('c1')['status']);
    }

    public function testDisabledDoesNothing(): void
    {
        $this->setVideoEnv(['SOCIAL_INBOX_ENABLED' => 'false']);
        $this->assertSame('disabled', $this->service()->run()['status']);
        $this->assertSame([], $this->http->requests);
    }

    // ── read side ───────────────────────────────────────────────────────────

    public function testStatsAndTheDailyReportSection(): void
    {
        $this->inbox([$this->item('c1', 'How do I file GSTR-1?'), $this->item('c9', 'Export broken'), $this->item('c5', 'lawyer next')]);
        $this->acceptReplies();
        $this->acceptFlow();
        $this->service()->run();

        $s = SocialInboxService::stats($this->db, gmdate('Y-m-d H:i:sP', $this->now - 3600), gmdate('Y-m-d H:i:sP', $this->now + 3600));
        $this->assertSame(3, $s['received']);
        $this->assertSame(['complaint' => 2, 'question' => 1], array_intersect_key($s['by_classification'], ['complaint' => 1, 'question' => 1]));
        $this->assertSame(3, $s['replies_sent']);
        $this->assertSame(2, $s['tickets']);
        $this->assertCount(1, $s['high_severity']);

        $date    = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->modify('+1 day')->format('Y-m-d');
        $summary = (new DailyReportBuilder($this->db, null, new \App\Libraries\Intelligence\Seo\SeoTrackerService($this->db, new \App\Libraries\Intelligence\Seo\DataForSeoSerpClient(null, null)), static fn (): bool => false, static fn (): string => 'email: test'))
            ->build($date, new \DateTimeImmutable('@' . (time() + 5)));
        $this->assertTrue($summary['sections']['social_inbox']['configured']);
        $text = DailyReportRenderer::text($summary);
        $this->assertStringContainsString('== Social inbox (last 24h) ==', $text);
        $this->assertStringContainsString('replies posted 3 · Flow tickets 2', $text);
        $this->assertStringContainsString('HIGH: linkedin @asha_c5', $text);
        $this->assertStringContainsString('Social inbox (last 24h)', DailyReportRenderer::html($summary));
    }
}
