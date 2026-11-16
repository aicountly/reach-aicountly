<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\AicountlyEmailEvents;
use App\Libraries\Distribution\ChannelDispatchService;
use App\Libraries\Distribution\Providers\AicountlyEmailSender;
use App\Libraries\JobService;
use App\Libraries\Settings\ReachConfig;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\DatabaseTestCase;
use Tests\Support\EnvOverride;

/**
 * EMAIL_PROVIDER=aicountly_email end to end on PostgreSQL: a campaign dispatch through
 * AicountlyEmailSender (scripted Email API) stores the delivery id on the attempt, a 429/503 that
 * outlasts the retries puts the recipient back and the next batch reuses the same Idempotency-Key;
 * then POST v1/inbound/aicountly-email/events — signature, freshness, dedupe on event_id, state
 * updates on the attempt, and bounced(hard) / complained / unsubscribed mirrored into suppression
 * and consent.
 *
 * @internal
 */
final class AicountlyEmailFlowTest extends DatabaseTestCase
{
    use EnvOverride;
    use FeatureTestTrait;

    private const SECRET = 'whsec_reach_test_0123456789abcdef';
    private const URL    = 'v1/inbound/aicountly-email/events';

    /** @var list<array{headers:array<string,string>, body:array<string,mixed>}> */
    private array $requests = [];

    /** @var list<array{status:int, body?:string, headers?:array<string,string>}> */
    private array $script = [];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('REACH_APP_URL', 'https://reach.example.test');
        $this->setEnv('DISTRIBUTION_POSTAL_ADDRESS', 'Aicountly, 1 Test Street, Pune 411001');
        $this->setEnv('AICOUNTLY_EMAIL_WEBHOOK_SECRET', self::SECRET);
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->saved = [];
        ReachConfig::flush();
        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->saved)) {
            $this->saved[$key] = getenv($key);
        }
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function sender(): AicountlyEmailSender
    {
        $http = function (string $method, string $url, array $headers, ?string $body): array {
            $this->requests[] = ['headers' => $headers, 'body' => (array) json_decode((string) $body, true)];
            $next = array_shift($this->script);
            if ($next === null) {
                $n = count($this->requests);

                return ['status' => 202, 'body' => (string) json_encode(['data' => ['id' => 'dlv_' . $n, 'category' => 'marketing', 'status' => 'queued']]), 'headers' => [], 'error' => ''];
            }

            return $next + ['body' => '', 'headers' => [], 'error' => ''];
        };

        return new AicountlyEmailSender('https://email.gh.aicountly.com/api', 'svc-key-test', $http, static function (int $s): void {
        }, 1);
    }

    private function service(): ChannelDispatchService
    {
        $sender = $this->sender();

        return new ChannelDispatchService(new JobService(), null, new AuditLogger(), static fn (string $channel) => $sender);
    }

    private function lead(string $name, string $email, bool $consent = true): int
    {
        $db = Database::connect();
        $db->table('reach_leads')->insert(['name' => $name, 'email' => $email]);
        $id = (int) $db->insertID();
        if ($consent) {
            $db->table('reach_channel_consents')->insert([
                'tenant_id' => 0, 'subject_type' => 'lead', 'subject_id' => $id, 'channel' => 'email',
                'purpose' => 'marketing', 'status' => 'granted', 'source' => 'test', 'captured_at' => date('Y-m-d H:i:s', time() - 60),
            ]);
        }

        return $id;
    }

    private function emailCampaign(): int
    {
        $db = Database::connect();
        $db->table('reach_email_campaigns')->insert([
            'subject' => 'Launch day', 'body_html' => '<p>Hello from Aicountly</p>', 'body_text' => 'Hello from Aicountly',
            'audience_filter' => json_encode(['all_leads' => true]), 'status' => 'approved', 'tenant_id' => 0,
        ]);

        return (int) $db->insertID();
    }

    /** @return list<array<string,mixed>> */
    private function attempts(int $dispatchId): array
    {
        return Database::connect()->table('reach_campaign_delivery_attempts')->where('dispatch_id', $dispatchId)->orderBy('id')->get()->getResultArray();
    }

    private function attemptFor(string $deliveryId): array
    {
        return Database::connect()->table('reach_campaign_delivery_attempts')->where('provider_message_id', $deliveryId)->get()->getRowArray();
    }

    private function post(array $event, ?string $secret = self::SECRET, ?int $ts = null)
    {
        $raw     = (string) json_encode($event);
        $stamp   = (string) ($ts ?? time());
        $headers = ['Content-Type' => 'application/json', 'X-Aicountly-Timestamp' => $stamp];
        if ($secret !== null) {
            $headers['X-Aicountly-Signature'] = AicountlyEmailEvents::sign($secret, $stamp, $raw);
        }

        return $this->withHeaders($headers)->withBody($raw)->call('POST', self::URL);
    }

    private static function json($res): array
    {
        return (array) json_decode((string) $res->getJSON(), true);
    }

    private function suppressed(string $email): ?array
    {
        return Database::connect()->table('reach_channel_suppressions')
            ->where('tenant_id', 0)->where('channel', 'email')
            ->where('address_hash', ChannelSuppressionModel::hashAddress(0, 'email', $email))->get()->getRowArray();
    }

    private function consentStatus(int $leadId): string
    {
        return (string) Database::connect()->table('reach_channel_consents')->where('subject_id', $leadId)->where('channel', 'email')
            ->orderBy('id', 'DESC')->get()->getRowArray()['status'];
    }

    /** Dispatch a campaign to the given leads; returns [dispatchId, address => delivery id]. */
    private function dispatched(): array
    {
        $svc   = $this->service();
        $start = $svc->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $this->emailCampaign(), 0, null);
        $this->assertSame('queued', $start['status']);
        $result = $svc->processBatch($start['dispatch_id']);
        $this->assertSame('finished', $result['status']);

        $ids = [];
        foreach ($this->requests as $i => $req) {
            $ids[$req['body']['to'][0]] = 'dlv_' . ($i + 1);
        }

        return [$start['dispatch_id'], $ids];
    }

    // ── sending ─────────────────────────────────────────────────────────────

    public function testCampaignDispatchStoresTheDeliveryIdAndSendsOneRecipientPerRequest(): void
    {
        $this->lead('Asha', 'asha@example.com');
        $this->lead('Bina', 'bina@example.com');
        [$dispatchId, $ids] = $this->dispatched();

        $this->assertCount(2, $this->requests);
        foreach ($this->requests as $req) {
            $this->assertSame('marketing', $req['body']['category']);
            $this->assertCount(1, $req['body']['to']);
            $this->assertStringStartsWith('https://reach.example.test/api/v1/public/unsubscribe?', $req['body']['unsubscribe_url']);
            $this->assertMatchesRegularExpression('/\Areach:d' . $dispatchId . ':r\d+\z/', $req['headers']['Idempotency-Key']);
        }
        $attempts = $this->attempts($dispatchId);
        $this->assertSame(['accepted', 'accepted'], array_column($attempts, 'status'));
        $this->assertSame(['aicountly_email', 'aicountly_email'], array_column($attempts, 'provider'));
        $this->assertEqualsCanonicalizing(array_values($ids), array_column($attempts, 'provider_message_id'));
        foreach ($attempts as $a) {
            $this->assertSame('reach:' . $a['idempotency_key'], $this->requests[array_search($a['provider_message_id'], array_values($ids), true)]['headers']['Idempotency-Key']);
        }
        $this->assertSame('completed', Database::connect()->table('reach_campaign_dispatches')->where('id', $dispatchId)->get()->getRowArray()['status']);
    }

    public function testUnavailableAfterRetriesRequeuesAndTheRetryReusesTheKey(): void
    {
        $this->lead('Asha', 'asha@example.com');
        $svc   = $this->service();
        $start = $svc->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $this->emailCampaign(), 0, null);

        $this->script = [['status' => 503, 'body' => '{"error":{"code":"mail_server_unavailable"}}'], ['status' => 503, 'body' => '{"error":{"code":"mail_server_unavailable"}}']];
        $first        = $svc->processBatch($start['dispatch_id']);
        $this->assertSame('rate_limited', $first['status'], 'deferred, not failed');
        $this->assertSame([], $this->attempts($start['dispatch_id']), 'nothing recorded as sent or failed');

        $this->script = [['status' => 200, 'body' => '{"data":{"id":"dlv_same","status":"queued"},"replayed":true}']];
        $second       = $svc->processBatch($start['dispatch_id'], 1);
        $this->assertSame('finished', $second['status']);
        $this->assertCount(3, $this->requests);
        $this->assertCount(1, array_unique(array_map(static fn (array $r): string => $r['headers']['Idempotency-Key'], $this->requests)), 'same Idempotency-Key across the deferral');
        $this->assertSame('dlv_same', $this->attempts($start['dispatch_id'])[0]['provider_message_id']);
    }

    public function testInvalidInputFailsTheRecipientWithoutRetry(): void
    {
        $this->lead('Asha', 'asha@example.com');
        $this->script = [['status' => 422, 'body' => '{"error":{"code":"invalid_input","details":{"field":"subject"}}}']];
        $svc          = $this->service();
        $start        = $svc->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $this->emailCampaign(), 0, null);
        $svc->processBatch($start['dispatch_id']);

        $this->assertCount(1, $this->requests);
        $attempt = $this->attempts($start['dispatch_id'])[0];
        $this->assertSame('failed', $attempt['status']);
        $this->assertSame('rejected', $attempt['failure_class']);
        $this->assertStringContainsString('field subject', $attempt['failure_detail']);
    }

    // ── webhook ─────────────────────────────────────────────────────────────

    public function testWebhookRejectsUnsignedStaleAndUnconfigured(): void
    {
        $event = ['event_id' => 1, 'event' => 'relayed', 'delivery_id' => 'dlv_x', 'recipient' => 'a@example.com'];

        $this->assertSame(401, $this->post($event, null)->response()->getStatusCode());
        $this->assertSame(401, $this->post($event, 'wrong-secret')->response()->getStatusCode());
        $this->assertSame(401, $this->post($event, self::SECRET, time() - 301)->response()->getStatusCode(), 'stale timestamp');
        $this->setEnv('AICOUNTLY_EMAIL_WEBHOOK_SECRET', null);
        $this->assertSame(503, $this->post($event)->response()->getStatusCode(), 'fail closed without a secret');
        $this->assertSame(0, Database::connect()->table('reach_campaign_provider_events')->countAllResults());
    }

    public function testWebhookValidatesTheEvent(): void
    {
        $this->assertSame(422, $this->post(['event' => 'relayed'])->response()->getStatusCode(), 'event_id required');
        $this->assertSame(422, $this->post(['event_id' => 5, 'event' => 'exploded'])->response()->getStatusCode());
        $this->assertSame(400, $this->post([['event_id' => 5, 'event' => 'relayed']])->response()->getStatusCode(), 'one event object');
    }

    public function testDeliveryStatesMoveForwardAndEventsAreDeduped(): void
    {
        $this->lead('Asha', 'asha@example.com');
        [, $ids] = $this->dispatched();
        $dlv = $ids['asha@example.com'];

        $res = $this->post(['event_id' => 100, 'event' => 'submitted', 'delivery_id' => $dlv, 'recipient' => 'asha@example.com', 'at' => '2026-11-16T10:00:00Z']);
        $this->assertSame(200, $res->response()->getStatusCode());
        $this->assertSame('sent', $this->attemptFor($dlv)['status']);

        $this->assertSame('processed', self::json($this->post(['event_id' => 101, 'event' => 'relayed', 'delivery_id' => $dlv, 'recipient' => 'asha@example.com']))['data']['status']);
        $attempt = $this->attemptFor($dlv);
        $this->assertSame('delivered', $attempt['status']);
        $this->assertNotNull($attempt['delivered_at']);

        // a late / redelivered earlier event never moves the state back
        $this->post(['event_id' => 102, 'event' => 'submitted', 'delivery_id' => $dlv, 'recipient' => 'asha@example.com']);
        $this->post(['event_id' => 103, 'event' => 'deferred', 'delivery_id' => $dlv, 'recipient' => 'asha@example.com']);
        $this->assertSame('delivered', $this->attemptFor($dlv)['status']);

        // the same event_id twice: second answer is duplicate and stores nothing
        $dup = self::json($this->post(['event_id' => 101, 'event' => 'relayed', 'delivery_id' => $dlv, 'recipient' => 'asha@example.com']));
        $this->assertSame('duplicate', $dup['data']['status']);
        $this->assertSame(4, Database::connect()->table('reach_campaign_provider_events')->where('provider', 'aicountly_email')->countAllResults());

        $stored = Database::connect()->table('reach_campaign_provider_events')->where('provider_event_id', '100')->get()->getRowArray();
        $this->assertSame((int) $this->attemptFor($dlv)['id'], (int) $stored['attempt_id']);
        $this->assertStringNotContainsString('asha@example.com', (string) $stored['raw_event'], 'recipient stored masked');
    }

    public function testHardBounceSuppressesSoftBounceDoesNot(): void
    {
        $this->lead('Asha', 'asha@example.com');
        $this->lead('Bina', 'bina@example.com');
        [, $ids] = $this->dispatched();

        $this->post(['event_id' => 200, 'event' => 'bounced', 'delivery_id' => $ids['asha@example.com'], 'recipient' => 'asha@example.com', 'dsn' => '5.1.1', 'bounce_class' => 'hard']);
        $hard = $this->attemptFor($ids['asha@example.com']);
        $this->assertSame('bounced', $hard['status']);
        $this->assertSame('permanent', $hard['failure_class']);
        $this->assertStringContainsString('5.1.1', $hard['failure_detail']);
        $this->assertSame('bounce', $this->suppressed('asha@example.com')['reason']);

        $this->post(['event_id' => 201, 'event' => 'bounced', 'delivery_id' => $ids['bina@example.com'], 'recipient' => 'bina@example.com', 'dsn' => '4.2.2', 'bounce_class' => 'mailbox_full']);
        $soft = $this->attemptFor($ids['bina@example.com']);
        $this->assertSame('bounced', $soft['status']);
        $this->assertSame('transient', $soft['failure_class']);
        $this->assertNull($this->suppressed('bina@example.com'), 'only hard bounces are suppressed');
    }

    public function testComplaintAndUnsubscribeSuppressAndRevokeConsent(): void
    {
        $asha = $this->lead('Asha', 'asha@example.com');
        $bina = $this->lead('Bina', 'bina@example.com');
        [, $ids] = $this->dispatched();

        $this->post(['event_id' => 300, 'event' => 'relayed', 'delivery_id' => $ids['asha@example.com'], 'recipient' => 'asha@example.com']);
        $res = self::json($this->post(['event_id' => 301, 'event' => 'complained', 'delivery_id' => $ids['asha@example.com'], 'recipient' => 'asha@example.com']));
        $this->assertTrue($res['data']['suppressed']);
        $this->assertTrue($res['data']['consent_revoked']);
        $this->assertSame('complained', $this->attemptFor($ids['asha@example.com'])['status'], 'a complaint after delivery still counts');
        $this->assertSame('complaint', $this->suppressed('asha@example.com')['reason']);
        $this->assertSame('revoked', $this->consentStatus($asha));

        // matched by the Idempotency-Key when the delivery id is not known locally
        $attempt = $this->attemptFor($ids['bina@example.com']);
        $this->post(['event_id' => 302, 'event' => 'unsubscribed', 'delivery_id' => 'dlv_unknown', 'idempotency_key' => 'reach:' . $attempt['idempotency_key'], 'recipient' => 'bina@example.com']);
        $this->assertSame('unsubscribed', Database::connect()->table('reach_campaign_delivery_attempts')->where('id', $attempt['id'])->get()->getRowArray()['status']);
        $this->assertSame('unsubscribe', $this->suppressed('bina@example.com')['reason']);
        $this->assertSame('revoked', $this->consentStatus($bina));

        // the next campaign skips both
        $this->requests = [];
        $start          = $this->service()->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $this->emailCampaign(), 0, null);
        $this->assertSame('failed', $start['status'], 'no eligible recipients left');
    }

    public function testUnknownDeliveryIsStillMirroredIntoSuppression(): void
    {
        $this->lead('Asha', 'asha@example.com');
        $this->dispatched(); // establishes the deployment's tenant (0)

        $res = $this->post(['event_id' => 400, 'event' => 'unsubscribed', 'delivery_id' => 'dlv_elsewhere', 'recipient' => 'Zed@Example.com']);
        $this->assertSame(200, $res->response()->getStatusCode());
        $this->assertNull(self::json($res)['data']['attempt_id']);
        $this->assertSame('unsubscribe', $this->suppressed('zed@example.com')['reason']);
    }
}
