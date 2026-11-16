<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\AicountlyEmailEvents;
use App\Libraries\Distribution\CampaignReadinessService;
use App\Libraries\Distribution\Providers\AicountlyEmailSender;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\GatewayEmailSender;
use App\Libraries\Distribution\Providers\MockEmailSender;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EnvOverride;

/**
 * AicountlyEmailSender against email-aicountly docs/DELIVERY_API.md, with a scripted HTTP double:
 * request shape, Idempotency-Key stability, 429/503 retried with the same key, 422/409/403 never
 * retried, selection only by EMAIL_PROVIDER, and the webhook signature check.
 *
 * @internal
 */
final class AicountlyEmailSenderTest extends CIUnitTestCase
{
    use EnvOverride;

    private const BASE = 'https://email.gh.aicountly.com/api';
    private const KEY  = 'svc-key-reach-0123456789';

    /** @var list<array{method:string, url:string, headers:array<string,string>, body:?string}> */
    private array $calls = [];

    /** @var list<int> */
    private array $slept = [];

    /** @param list<array{status:int, body?:string, headers?:array<string,string>, error?:string}> $script */
    private function sender(array $script, int $maxRetries = 2): AicountlyEmailSender
    {
        $http = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$script): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            $next = array_shift($script) ?? ['status' => 500, 'body' => '{"error":{"code":"unscripted"}}'];

            return $next + ['body' => '', 'headers' => [], 'error' => ''];
        };

        return new AicountlyEmailSender(self::BASE, self::KEY, $http, function (int $s): void {
            $this->slept[] = $s;
        }, $maxRetries, 15, 'Aicountly Reach', 'hello@aicountly.com');
    }

    private static function message(string $key = 'd12:r34', array $meta = []): ChannelMessage
    {
        return new ChannelMessage(
            idempotencyKey: $key,
            recipientAddress: 'Lead@Example.com',
            content: '<p>Offer</p><a href="https://reach.example.test/api/v1/public/unsubscribe?t=0&e=x&s=y">Unsubscribe</a>',
            metadata: $meta + [
                'subject'         => 'Diwali offer',
                'body_text'       => "Offer\nUnsubscribe: https://reach.example.test/u",
                'unsubscribe_url' => 'https://reach.example.test/api/v1/public/unsubscribe?t=0&e=x&s=y',
            ],
        );
    }

    private static function accepted(string $id = 'dlv_01HX', int $status = 202, bool $replayed = false): array
    {
        $body = ['data' => ['id' => $id, 'category' => 'marketing', 'status' => 'queued', 'recipients' => ['lead@example.com']]];
        if ($replayed) {
            $body['replayed'] = true;
        }

        return ['status' => $status, 'body' => (string) json_encode($body)];
    }

    private static function error(int $status, string $code, ?string $field = null): array
    {
        $err = ['code' => $code, 'message' => $code . ' message'];
        if ($field !== null) {
            $err['details'] = ['field' => $field];
        }

        return ['status' => $status, 'body' => (string) json_encode(['error' => $err])];
    }

    // ── request shape ───────────────────────────────────────────────────────

    public function testRequestShapeFollowsTheContract(): void
    {
        $receipt = $this->sender([self::accepted()])->send(self::message());

        $this->assertCount(1, $this->calls);
        $call = $this->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://email.gh.aicountly.com/api/v1/service/deliveries', $call['url']);
        $this->assertSame(self::KEY, $call['headers']['X-Service-Key']);
        $this->assertSame('reach:d12:r34', $call['headers']['Idempotency-Key']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
        $this->assertArrayNotHasKey('X-Actor-Uuid', $call['headers'], 'the product acts as itself');

        $body = json_decode((string) $call['body'], true);
        $this->assertSame('marketing', $body['category']);
        $this->assertSame(['lead@example.com'], $body['to'], 'marketing: exactly one recipient');
        $this->assertSame('Diwali offer', $body['subject']);
        $this->assertSame("Offer\nUnsubscribe: https://reach.example.test/u", $body['body_text']);
        $this->assertStringContainsString('<p>Offer</p>', $body['body_html']);
        $this->assertSame('https://reach.example.test/api/v1/public/unsubscribe?t=0&e=x&s=y', $body['unsubscribe_url']);
        $this->assertSame('Aicountly Reach', $body['from_name']);
        $this->assertSame('hello@aicountly.com', $body['reply_to']);
        $this->assertSame('reach.campaign.email', $body['template_ref']);
        $this->assertArrayNotHasKey('from', $body, 'a product cannot choose its From');
        $this->assertArrayNotHasKey('from_email', $body);

        $this->assertTrue($receipt->isAccepted());
        $this->assertSame('dlv_01HX', $receipt->providerMessageId, 'the delivery id is stored as the provider message id');
        $this->assertSame('reach:d12:r34', $receipt->meta['idempotency_key']);
        $this->assertFalse($receipt->meta['replayed']);
        $this->assertStringNotContainsString(self::KEY, (string) $receipt->rawResponse);
        $this->assertSame([], $this->slept);
    }

    public function testPlainTextIsDerivedWhenTheCampaignHasNone(): void
    {
        $this->sender([self::accepted()])->send(self::message('d1:r1', ['body_text' => '']));
        $body = json_decode((string) $this->calls[0]['body'], true);
        $this->assertStringContainsString('Offer', $body['body_text']);
        $this->assertStringNotContainsString('<p>', $body['body_text']);
    }

    public function testBaseUrlMayBeGivenWithOrWithoutV1AndMustBeHttps(): void
    {
        $this->assertSame('https://email.apis.aicountly.com/v1/service/deliveries', AicountlyEmailSender::deliveriesUrl('https://email.apis.aicountly.com'));
        $this->assertSame('https://email.apis.aicountly.com/v1/service/deliveries', AicountlyEmailSender::deliveriesUrl('https://email.apis.aicountly.com/v1/'));
        $this->assertSame('https://email.aicountly.com/api/v1/service/deliveries', AicountlyEmailSender::deliveriesUrl('https://email.aicountly.com/api/v1'));
        $this->assertNull(AicountlyEmailSender::deliveriesUrl('http://email.apis.aicountly.com'));
        $this->assertNull(AicountlyEmailSender::deliveriesUrl(''));
    }

    public function testHttpsOneClickUnsubscribeUrlIsRequiredBeforeAnythingIsSent(): void
    {
        foreach (['', 'http://reach.example.test/u', 'mailto:unsub@example.com'] as $url) {
            try {
                $this->sender([self::accepted()])->send(self::message('d1:r2', ['unsubscribe_url' => $url]));
                $this->fail('marketing mail without an https unsubscribe_url must not be sent');
            } catch (\RuntimeException $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        $this->assertSame([], $this->calls);
    }

    public function testUnconfiguredSenderFailsClosed(): void
    {
        $sender = new AicountlyEmailSender('', '', fn () => $this->fail('no request when unconfigured'));
        $this->assertFalse($sender->isEnabled());
        $this->expectException(ProviderNotConfiguredException::class);
        $sender->send(self::message());
    }

    // ── idempotency ─────────────────────────────────────────────────────────

    public function testIdempotencyKeyIsStablePerRecipientMessage(): void
    {
        $this->assertSame('reach:d12:r34', AicountlyEmailSender::idempotencyKey('d12:r34'));
        $this->assertSame(AicountlyEmailSender::idempotencyKey('d12:r34'), AicountlyEmailSender::idempotencyKey('d12:r34'));
        $this->assertNotSame(AicountlyEmailSender::idempotencyKey('d12:r34'), AicountlyEmailSender::idempotencyKey('d12:r35'));
        // short keys are padded by the prefix into the 8–120 range
        $this->assertSame('reach:d1:r1', AicountlyEmailSender::idempotencyKey('d1:r1'));
        // too long / not printable → stable hash, still within the contract
        $long = str_repeat('x', 200);
        $this->assertSame(AicountlyEmailSender::idempotencyKey($long), AicountlyEmailSender::idempotencyKey($long));
        $this->assertMatchesRegularExpression('/\A[\x21-\x7E]{8,120}\z/', AicountlyEmailSender::idempotencyKey($long));
        $this->assertMatchesRegularExpression('/\A[\x21-\x7E]{8,120}\z/', AicountlyEmailSender::idempotencyKey("a b\tc"));
        // and back to the attempt key for webhook matching
        $this->assertSame('d12:r34', AicountlyEmailSender::messageKeyFrom('reach:d12:r34'));
        $this->assertNull(AicountlyEmailSender::messageKeyFrom(AicountlyEmailSender::idempotencyKey($long)));
        $this->assertNull(AicountlyEmailSender::messageKeyFrom('my:otp:1234'));

        // two sends of the same message carry the same key; a replay answers the same id
        $sender = $this->sender([self::accepted('dlv_A'), self::accepted('dlv_A', 200, true)]);
        $first  = $sender->send(self::message('d7:r9'));
        $second = $sender->send(self::message('d7:r9'));
        $this->assertSame($this->calls[0]['headers']['Idempotency-Key'], $this->calls[1]['headers']['Idempotency-Key']);
        $this->assertSame($first->providerMessageId, $second->providerMessageId);
        $this->assertTrue($second->meta['replayed']);
        $this->assertTrue($second->isAccepted());
    }

    // ── retries ─────────────────────────────────────────────────────────────

    public function testRateLimitAndUnavailableAreRetriedWithTheSameKey(): void
    {
        $receipt = $this->sender([
            self::error(429, 'rate_limited') + ['headers' => ['Retry-After' => '3']],
            self::error(503, 'mail_server_unavailable'),
            self::accepted('dlv_R'),
        ])->send(self::message('d5:r6'));

        $this->assertSame('dlv_R', $receipt->providerMessageId);
        $this->assertCount(3, $this->calls);
        $keys = array_unique(array_map(static fn (array $c): string => $c['headers']['Idempotency-Key'], $this->calls));
        $this->assertSame(['reach:d5:r6'], array_values($keys), 'every retry reuses the same Idempotency-Key');
        $bodies = array_unique(array_map(static fn (array $c): string => (string) $c['body'], $this->calls));
        $this->assertCount(1, $bodies, 'and the same message');
        $this->assertSame([3, 2], $this->slept, 'Retry-After honoured, then backoff');
        $this->assertSame(3, $receipt->meta['attempts']);
    }

    public function testRetryAfterIsCapped(): void
    {
        $this->sender([self::error(429, 'rate_limited') + ['headers' => ['retry-after' => '3600']], self::accepted()])->send(self::message());
        $this->assertSame([AicountlyEmailSender::MAX_RETRY_WAIT], $this->slept);
    }

    public function testTimeoutIsRetriedWithTheSameKey(): void
    {
        $this->sender([['status' => 0, 'error' => 'Operation timed out'], self::accepted('dlv_T')])->send(self::message('d8:r1'));
        $this->assertCount(2, $this->calls);
        $this->assertSame($this->calls[0]['headers']['Idempotency-Key'], $this->calls[1]['headers']['Idempotency-Key']);
    }

    public function testStillRefusedAfterRetriesDefersAsRateLimitSoTheDispatchRetriesLater(): void
    {
        try {
            $this->sender([self::error(503, 'mail_server_unavailable'), self::error(503, 'mail_server_unavailable'), self::error(429, 'rate_limited')])->send(self::message('d2:r2'));
            $this->fail('exhausted retries must throw');
        } catch (\RuntimeException $e) {
            // 429 → ChannelDispatchService drops the in-flight attempt and requeues the batch;
            // the next try derives the same key from the same dispatch + recipient.
            $this->assertSame(429, $e->getCode());
            $this->assertStringContainsString('reach:d2:r2', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
        $this->assertCount(3, $this->calls, '1 try + 2 retries');
    }

    public function testInvalidInputIsNeverRetried(): void
    {
        try {
            $this->sender([self::error(422, 'invalid_input', 'unsubscribe_url'), self::accepted()])->send(self::message());
            $this->fail('422 must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('field unsubscribe_url', $e->getMessage());
        }
        $this->assertCount(1, $this->calls, '422: fix, do not retry unchanged');
        $this->assertSame([], $this->slept);
    }

    public function testConflictIsNeverRetried(): void
    {
        try {
            $this->sender([self::error(409, 'idempotency_conflict'), self::accepted()])->send(self::message());
            $this->fail('409 must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertCount(1, $this->calls);
    }

    public function testForbiddenIsConfigurationNotRetried(): void
    {
        foreach ([[403, 'category_not_permitted'], [401, 'unauthenticated']] as [$status, $code]) {
            $this->calls = [];
            try {
                $this->sender([self::error($status, $code), self::accepted()])->send(self::message());
                $this->fail($status . ' must throw');
            } catch (ProviderNotConfiguredException $e) {
                $this->assertStringContainsString((string) $status, $e->getMessage());
                $this->assertStringContainsString('DELIVERY_PRODUCTS', $e->getMessage());
            }
            $this->assertCount(1, $this->calls);
        }
    }

    public function testAcceptedWithoutAnIdIsNotInvented(): void
    {
        $this->expectExceptionCode(502);
        $this->sender([['status' => 202, 'body' => '{"data":{}}']])->send(self::message());
    }

    // ── status ──────────────────────────────────────────────────────────────

    public function testDeliveryStatusIsNormalised(): void
    {
        $status = $this->sender([[
            'status' => 200,
            'body'   => (string) json_encode(['data' => ['id' => 'dlv_S', 'recipients' => [['address' => 'lead@example.com', 'status' => 'bounced', 'dsn' => '5.1.1', 'bounce_class' => 'hard']]]]),
        ]])->getStatus('dlv_S');
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('https://email.gh.aicountly.com/api/v1/service/deliveries/dlv_S', $this->calls[0]['url']);
        $this->assertSame('bounced', $status->normalisedStatus);
        $this->assertSame('permanent', $status->failureClass);

        $this->assertSame('delivered', AicountlyEmailSender::attemptStatusFor('relayed'));
        $this->assertSame('sent', AicountlyEmailSender::attemptStatusFor('submitted'));
        $this->assertNull(AicountlyEmailSender::attemptStatusFor('deferred'));
        $this->assertSame('transient', AicountlyEmailSender::bounceFailureClass('mailbox_full'));
    }

    // ── selection ───────────────────────────────────────────────────────────

    public function testSelectedOnlyByEmailProvider(): void
    {
        $this->withEnv('EMAIL_PROVIDER', null, function (): void {
            $default = ChannelProviderFactory::makeEmailSender();
            $this->assertTrue($default instanceof MockEmailSender || $default instanceof GatewayEmailSender, 'default unchanged');
            $this->assertNotInstanceOf(AicountlyEmailSender::class, $default);
        });

        $sender = $this->withEnv('EMAIL_PROVIDER', 'aicountly_email', static fn () => ChannelProviderFactory::makeEmailSender());
        $this->assertInstanceOf(AicountlyEmailSender::class, $sender);
        $this->assertSame('aicountly_email', $sender->providerName());

        $configured = $this->withEnv('AICOUNTLY_EMAIL_API_URL', self::BASE, fn () => $this->withEnv('AICOUNTLY_EMAIL_SERVICE_KEY', self::KEY, static fn () => AicountlyEmailSender::fromEnv()));
        $this->assertTrue($configured->isEnabled());
        $this->assertFalse($this->withEnv('AICOUNTLY_EMAIL_API_URL', null, static fn () => AicountlyEmailSender::fromEnv())->isEnabled());
    }

    public function testReadinessDescribesTheSelectedProviderWithoutSecrets(): void
    {
        $report = $this->withEnv('EMAIL_PROVIDER', 'aicountly_email', fn () => $this->withEnv('AICOUNTLY_EMAIL_API_URL', self::BASE, fn () => $this->withEnv('AICOUNTLY_EMAIL_SERVICE_KEY', self::KEY, fn () => $this->withEnv('AICOUNTLY_EMAIL_WEBHOOK_SECRET', null, static fn () => (new CampaignReadinessService())->report()))));
        $email  = array_column($report['channels'], null, 'channel')['email'];
        $checks = array_column($email['checks'], null, 'key');

        $this->assertSame('aicountly_email', $email['provider']);
        $this->assertTrue($checks['AICOUNTLY_EMAIL_API_URL']['ok']);
        $this->assertTrue($checks['AICOUNTLY_EMAIL_SERVICE_KEY']['ok']);
        $this->assertFalse($checks['AICOUNTLY_EMAIL_WEBHOOK_SECRET']['ok']);
        $this->assertSame('not_configured', $email['state']);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($report));
    }

    // ── webhook signature ───────────────────────────────────────────────────

    public function testWebhookSignatureVerification(): void
    {
        $secret = 'whsec_test_0123456789';
        $now    = 1_800_000_000;
        $body   = '{"event_id":991,"event":"bounced","delivery_id":"dlv_1","recipient":"x@example.com","bounce_class":"hard"}';
        $ts     = (string) $now;
        $sig    = AicountlyEmailEvents::sign($secret, $ts, $body);

        $this->assertSame('sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret), $sig);
        $this->assertNull(AicountlyEmailEvents::verifySignature($secret, $ts, $sig, $body, $now));
        $this->assertNull(AicountlyEmailEvents::verifySignature($secret, $ts, strtoupper($sig), $body, $now), 'hex case does not matter');
        $this->assertNull(AicountlyEmailEvents::verifySignature($secret, $ts, $sig, $body, $now + 299));

        $this->assertSame('signature mismatch', AicountlyEmailEvents::verifySignature('other-secret', $ts, $sig, $body, $now));
        $this->assertSame('signature mismatch', AicountlyEmailEvents::verifySignature($secret, $ts, $sig, $body . ' ', $now), 'tampered body');
        $this->assertSame('signature mismatch', AicountlyEmailEvents::verifySignature($secret, (string) ($now + 1), $sig, $body, $now), 'timestamp is signed');
        $this->assertSame('stale timestamp', AicountlyEmailEvents::verifySignature($secret, $ts, $sig, $body, $now + 301));
        $this->assertSame('stale timestamp', AicountlyEmailEvents::verifySignature($secret, $ts, $sig, $body, $now - 301), 'too far in the future');
        $this->assertSame('missing or malformed signature', AicountlyEmailEvents::verifySignature($secret, $ts, '', $body, $now));
        $this->assertSame('missing or malformed signature', AicountlyEmailEvents::verifySignature($secret, $ts, hash_hmac('sha256', $ts . '.' . $body, $secret), $body, $now));
        $this->assertSame('missing or malformed timestamp', AicountlyEmailEvents::verifySignature($secret, '', $sig, $body, $now));
        $this->assertSame('webhook secret not configured', AicountlyEmailEvents::verifySignature('', $ts, $sig, $body, $now));

        $sender = new AicountlyEmailSender(self::BASE, self::KEY);
        $fresh  = (string) time();
        $this->withEnv('AICOUNTLY_EMAIL_WEBHOOK_SECRET', $secret, function () use ($sender, $fresh, $body, $secret): void {
            $this->assertTrue($sender->verifyCallback(['X-Aicountly-Timestamp' => $fresh, 'X-Aicountly-Signature' => AicountlyEmailEvents::sign($secret, $fresh, $body)], $body));
            $this->assertFalse($sender->verifyCallback(['X-Aicountly-Timestamp' => $fresh, 'X-Aicountly-Signature' => 'sha256=00'], $body));
        });
    }
}
