<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Libraries\Email\EmailDispatcher;
use App\Libraries\Email\EmailProviderResolver;
use App\Libraries\Email\HttpApiProviderTransport;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * EmailDispatcher with fake transports and a fake Console: normal vs resend order, failover,
 * honest failure (never "sent" without a provider's acceptance), secret redaction, sender identity,
 * and the HTTP API transport's EMAIL_API_FORMAT behaviour.
 *
 * @internal
 */
final class EmailDispatcherTest extends CIUnitTestCase
{
    private string $dir;
    /** @var list<array{provider:string, msg:array<string,mixed>}> */
    private array $log = [];
    /** @var array<string, array<string,mixed>|\Throwable> */
    private array $answers = [];
    /** @var array{status:int, body:string, error:string} */
    private array $console;
    private string $envKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/reach-dispatch-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0700, true);
        $this->console = ['status' => 200, 'body' => (string) json_encode(['data' => ['ttl_seconds' => 300, 'providers' => EmailProviderResolverTest::PROVIDERS]]), 'error' => ''];
        EmailProviderResolver::clearMemo();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        EmailProviderResolver::clearMemo();
        parent::tearDown();
    }

    private function dispatcher(): EmailDispatcher
    {
        $resolver = new EmailProviderResolver(
            fn (): array => $this->console,
            $this->dir,
            static fn (): int => 1_000_000,
            fn (string $k): string => ['CONSOLE_API_URL' => 'https://console.test/api', 'CONSOLE_API_TOKEN' => 't', 'EMAIL_API_KEY' => $this->envKey][$k] ?? '',
        );
        $test = $this;

        return new EmailDispatcher($resolver, static fn (array $provider): object => new class ($test) {
            public function __construct(private EmailDispatcherTest $t)
            {
            }

            public function send(array $provider, array $msg): array
            {
                return $this->t->transportSend($provider, $msg);
            }
        });
    }

    /** @internal called by the fake transport */
    public function transportSend(array $provider, array $msg): array
    {
        $this->log[] = ['provider' => $provider['code'], 'msg' => $msg];
        $a = $this->answers[$provider['code']] ?? ['ok' => false, 'error' => 'unscripted'];
        if ($a instanceof \Throwable) {
            throw $a;
        }

        return $a;
    }

    public function testOrder(): void
    {
        $p = [['code' => 'a'], ['code' => 'b'], ['code' => 'c']];
        $this->assertSame(['a', 'b', 'c'], array_column(EmailDispatcher::order($p, false), 'code'));
        $this->assertSame(['b', 'c', 'a'], array_column(EmailDispatcher::order($p, true), 'code'));
        $this->assertSame(['a'], array_column(EmailDispatcher::order([['code' => 'a']], true), 'code'));
    }

    public function testNormalSendUsesThePrimary(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => true, 'id' => 'B1', 'mode' => 'multipart'], 'aicountly_smtp' => ['ok' => true]];
        $r = $this->dispatcher()->send('lead@example.com', 'Hi', '<p>Hello<br>there</p>');

        $this->assertTrue($r['ok']);
        $this->assertSame('itwalk_api', $r['provider']);
        $this->assertSame('B1', $r['id']);
        $this->assertSame('multipart', $r['mode']);
        $this->assertSame(['itwalk_api'], array_column($this->log, 'provider'));
        $this->assertSame("Hello\nthere", $this->log[0]['msg']['text']);
    }

    public function testPrimaryFailureFailsOver(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => false, 'error' => 'HTTP 500'], 'aicountly_smtp' => ['ok' => true, 'id' => 'm@x']];
        $r = $this->dispatcher()->send('lead@example.com', 'Hi', '<p>x</p>');

        $this->assertTrue($r['ok']);
        $this->assertSame('aicountly_smtp', $r['provider']);
        $this->assertSame([['provider' => 'itwalk_api', 'type' => 'http_api', 'ok' => false, 'error' => 'HTTP 500'], ['provider' => 'aicountly_smtp', 'type' => 'smtp', 'ok' => true, 'error' => null]], $r['attempts']);
    }

    public function testResendStartsWithTheFallbackThenRetriesThePrimary(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => true], 'aicountly_smtp' => ['ok' => true]];
        $r = $this->dispatcher()->send('lead@example.com', 'Hi', '<p>x</p>', null, ['resend' => true]);
        $this->assertSame('aicountly_smtp', $r['provider']);
        $this->assertTrue($r['resend']);

        $this->log     = [];
        $this->answers = ['itwalk_api' => ['ok' => true, 'id' => 'B2'], 'aicountly_smtp' => ['ok' => false, 'error' => 'SMTP 421 at connect']];
        $r = $this->dispatcher()->send('lead@example.com', 'Hi', '<p>x</p>', null, ['resend' => true]);
        $this->assertSame('itwalk_api', $r['provider']);
        $this->assertSame(['aicountly_smtp', 'itwalk_api'], array_column($this->log, 'provider'));
    }

    public function testEveryProviderFailingIsAnHonestFailureWithoutSecrets(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => false, 'error' => 'bad key itwalk-key-123456'], 'aicountly_smtp' => new \RuntimeException('auth smtp-pass-XYZ refused')];
        $r = $this->dispatcher()->send('lead@example.com', 'Hi', '<p>x</p>');

        $this->assertFalse($r['ok']);
        $this->assertNull($r['provider']);
        $this->assertStringContainsString('itwalk_api: ', (string) $r['error']);
        $this->assertStringContainsString('aicountly_smtp: ', (string) $r['error']);
        $json = (string) json_encode($r);
        $this->assertStringNotContainsString('itwalk-key-123456', $json);
        $this->assertStringNotContainsString('smtp-pass-XYZ', $json);
        $this->assertStringContainsString('[redacted]', $json);
    }

    public function testNoProviderMeansNoAttemptAndNoSuccess(): void
    {
        $this->console = ['status' => 0, 'body' => '', 'error' => 'down'];
        $r = $this->dispatcher()->send('lead@example.com', 'Hi', '<p>x</p>');

        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->log);
        $this->assertStringStartsWith('No email provider is configured', (string) $r['error']);
    }

    public function testConsoleDownUsesTheEnvKeyAsLastResort(): void
    {
        $this->console = ['status' => 0, 'body' => '', 'error' => 'down'];
        $this->envKey  = 'env-key-999';
        $this->answers = ['env_email_api' => ['ok' => true, 'id' => 'E1']];
        $r = $this->dispatcher()->send('lead@example.com', 'Hi', '<p>x</p>');

        $this->assertTrue($r['ok']);
        $this->assertSame('env_email_api', $r['provider']);
        $this->assertSame('env', $r['source']);
    }

    public function testInvalidRecipientIsRefusedBeforeAnyProvider(): void
    {
        $r = $this->dispatcher()->send([['email' => 'ok@example.com'], ['email' => 'nope']], 'Hi', '<p>x</p>');
        $this->assertFalse($r['ok']);
        $this->assertSame('Invalid recipient email.', $r['error']);
        $this->assertSame([], $this->log);
    }

    public function testSenderIdentityAndExtraHeaders(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => false, 'error' => 'x'], 'aicountly_smtp' => ['ok' => true]];
        $this->dispatcher()->send('lead@example.com', 'Hi', '<p>x</p>', 'plain', ['headers' => ['List-Unsubscribe' => '<https://u>']]);

        [$http, $smtp] = [$this->log[0]['msg'], $this->log[1]['msg']];
        $this->assertSame('reach@aicountly.com', $smtp['from_email'], 'the SMTP provider\'s own from address');
        $this->assertSame('<https://u>', $smtp['headers']['List-Unsubscribe']);
        $this->assertSame('plain', $smtp['text']);
        $this->assertNotSame('', $http['from_email']);
        $this->assertNotSame('', $http['from_name']);
    }

    public function testHttpApiTransportKeepsEmailApiFormatBehaviour(): void
    {
        $calls = [];
        $ok    = ['response' => '{"bulkId":"B1","messages":[{"messageId":"M1","status":{"groupName":"PENDING"}}]}', 'http_code' => 200, 'curl_error' => ''];
        $http  = static function (array $answers) use (&$calls): callable {
            $calls = [];

            return static function (string $url, string $mode, array $headers, $body) use (&$calls, &$answers): array {
                $calls[] = ['url' => $url, 'mode' => $mode, 'headers' => $headers, 'body' => $body];

                return array_shift($answers) ?? ['response' => '', 'http_code' => 500, 'curl_error' => ''];
            };
        };
        $prov = static fn (string $url, string $format): array => ['code' => 'itwalk_api', 'type' => 'http_api', 'config' => ['api_url' => $url, 'api_format' => $format], 'secret' => 'k-123456'];
        $msg  = ['to' => 'a@example.com', 'subject' => 'S', 'html' => '<p>h</p>', 'text' => 'h', 'from_email' => 'support@aicountly.com', 'from_name' => 'Aicountly Reach'];

        $r = (new HttpApiProviderTransport($http([$ok])))->send($prov('https://e.api.itwalk.in/email/1/send', ''), $msg);
        $this->assertTrue($r['ok']);
        $this->assertSame('B1', $r['id']);
        $this->assertSame(['multipart'], array_column($calls, 'mode'), 'auto + /email/1/send: multipart only');
        $this->assertContains('Authorization: App k-123456', $calls[0]['headers']);

        $r = (new HttpApiProviderTransport($http([$ok])))->send($prov('https://e.api.itwalk.in/email/1/send', 'json'), $msg);
        $this->assertSame('https://e.api.itwalk.in/email/3/send', $calls[0]['url']);
        $this->assertSame('json', $r['mode']);

        $r = (new HttpApiProviderTransport($http([['response' => '', 'http_code' => 415, 'curl_error' => ''], $ok])))->send($prov('https://mail.example/send', 'auto'), $msg);
        $this->assertTrue($r['ok']);
        $this->assertSame(['json', 'multipart'], array_column($calls, 'mode'));

        $r = (new HttpApiProviderTransport($http([['response' => '{}', 'http_code' => 200, 'curl_error' => '']])))->send($prov('https://e.api.itwalk.in/email/1/send', ''), $msg);
        $this->assertFalse($r['ok'], '2xx without a message id is not "sent"');

        $r = (new HttpApiProviderTransport($http([['response' => '{"requestError":{"serviceException":{"text":"Invalid login details k-123456"}}}', 'http_code' => 200, 'curl_error' => '']])))->send($prov('https://e.api.itwalk.in/email/1/send', ''), $msg);
        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString('k-123456', (string) $r['error']);
    }
}
