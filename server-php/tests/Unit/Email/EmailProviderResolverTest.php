<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Libraries\Email\EmailProviderResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * EmailProviderResolver against a fake Console: ordering, TTL cache (memory + encrypted file),
 * the 15-minute stale window and back-off on outage, 401/403, empty / odd answers, and the
 * .env EMAIL_API_KEY last resort. Nothing leaves the process.
 *
 * @internal
 */
final class EmailProviderResolverTest extends CIUnitTestCase
{
    private string $dir;
    private int $now = 1_000_000;
    /** @var array<string,string> */
    private array $env = [];
    /** @var list<array{url:string, token:string}> */
    private array $calls = [];
    /** @var array{status:int, body:string, error:string} */
    private array $next = ['status' => 0, 'body' => '', 'error' => 'down'];

    public const PROVIDERS = [
        ['code' => 'aicountly_smtp', 'type' => 'smtp', 'priority' => 20, 'config' => ['host' => 'mail.aicountly.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'reach@aicountly.com', 'from_email' => 'reach@aicountly.com'], 'secret' => 'smtp-pass-XYZ'],
        ['code' => 'itwalk_api', 'type' => 'http_api', 'priority' => 10, 'config' => ['api_url' => 'https://e.api.itwalk.in/email/1/send', 'api_format' => 'auto'], 'secret' => 'itwalk-key-123456'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/reach-email-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0700, true);
        $this->env = ['CONSOLE_API_URL' => 'https://console.test/api', 'CONSOLE_API_TOKEN' => 'reach-caller-key', 'EMAIL_API_KEY' => 'env-key-999', 'EMAIL_API_FORMAT' => 'json'];
        EmailProviderResolver::clearMemo();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        EmailProviderResolver::clearMemo();
        parent::tearDown();
    }

    private function resolver(): EmailProviderResolver
    {
        return new EmailProviderResolver(
            function (string $url, string $token): array {
                $this->calls[] = ['url' => $url, 'token' => $token];

                return $this->next;
            },
            $this->dir,
            fn (): int => $this->now,
            fn (string $k): string => $this->env[$k] ?? '',
        );
    }

    private function answer(array $providers, int $ttl = 300): void
    {
        $this->next = ['status' => 200, 'body' => (string) json_encode(['success' => true, 'data' => ['app_code' => 'reach', 'ttl_seconds' => $ttl, 'providers' => $providers]]), 'error' => ''];
    }

    private function restart(): void
    {
        EmailProviderResolver::clearMemo(); // a new PHP process: only the file cache survives
    }

    public function testReadsConsoleWithTheCallerKeyAndOrdersByPriority(): void
    {
        $this->answer(self::PROVIDERS);
        $r = $this->resolver()->resolve();

        $this->assertSame([['url' => 'https://console.test/api/portal/email/providers?app_code=reach', 'token' => 'reach-caller-key']], $this->calls);
        $this->assertSame('console', $r['source']);
        $this->assertSame(['itwalk_api', 'aicountly_smtp'], array_column($r['providers'], 'code'));
        $this->assertSame('itwalk-key-123456', $r['providers'][0]['secret']);
    }

    public function testCachesForTheTtlInMemoryAndInAnEncryptedFile(): void
    {
        $this->answer(self::PROVIDERS);
        $this->resolver()->resolve();
        $this->resolver()->resolve();
        $this->assertCount(1, $this->calls, 'memory cache');

        $this->restart();
        $this->now += 299;
        $this->assertSame('console', $this->resolver()->resolve()['source']);
        $this->assertCount(1, $this->calls, 'file cache inside the TTL');

        $files = glob($this->dir . '/*.cache') ?: [];
        $this->assertCount(1, $files);
        $blob = (string) file_get_contents($files[0]);
        $this->assertStringNotContainsString('itwalk-key', $blob . base64_decode($blob));
        $this->assertStringNotContainsString('smtp-pass', $blob . base64_decode($blob));
        $this->assertSame(0600, fileperms($files[0]) & 0777);

        $this->restart();
        $this->now += 2;
        $this->resolver()->resolve();
        $this->assertCount(2, $this->calls, 'TTL over: Console asked again');
    }

    public function testConsoleOutageUsesTheStaleListForFifteenMinutesThenTheEnvKey(): void
    {
        $this->answer(self::PROVIDERS);
        $this->resolver()->resolve();

        $this->restart();
        $this->now += 301;
        $this->next = ['status' => 502, 'body' => '', 'error' => ''];
        $r = $this->resolver()->resolve();
        $this->assertSame('console_stale', $r['source']);
        $this->assertSame(['itwalk_api', 'aicountly_smtp'], array_column($r['providers'], 'code'));
        $this->assertStringContainsString('cached Console list', EmailProviderResolver::describe($r));

        $this->restart();
        $this->now += 30;
        $this->resolver()->resolve();
        $this->assertCount(2, $this->calls, 'back-off: no new lookup within 60 s of a failure');

        $this->restart();
        $this->now = 1_000_000 + 300 + 900 + 1;
        $r = $this->resolver()->resolve();
        $this->assertSame('env', $r['source']);
        $this->assertSame(['env_email_api'], array_column($r['providers'], 'code'));
        $this->assertSame('env-key-999', $r['providers'][0]['secret']);
        $this->assertSame('json', $r['providers'][0]['config']['api_format'], 'EMAIL_API_FORMAT kept');

        $this->restart();
        $this->now += 61;
        $this->answer(self::PROVIDERS);
        $this->assertSame('console', $this->resolver()->resolve()['source']);
        $this->assertSame([], glob($this->dir . '/*.fail') ?: [], 'back-off cleared after success');
    }

    public function testRefusalDropsTheCacheAndFallsBackToEnv(): void
    {
        $this->answer(self::PROVIDERS);
        $this->resolver()->resolve();
        $this->restart();
        $this->now += 400;
        $this->next = ['status' => 401, 'body' => '{}', 'error' => ''];

        $r = $this->resolver()->resolve();
        $this->assertSame('env', $r['source']);
        $this->assertStringContainsString('HTTP 401', (string) $r['note']);
        $this->assertSame([], glob($this->dir . '/*.cache') ?: []);
    }

    public function testEmptyOrUnusableAnswersFallBackToEnv(): void
    {
        $this->answer([]);
        $r = $this->resolver()->resolve();
        $this->assertSame('env', $r['source']);
        $this->assertStringContainsString('no email provider for reach', (string) $r['note']);

        $this->restart();
        array_map('unlink', glob($this->dir . '/*') ?: []);
        $this->answer([['code' => 'x', 'type' => 'fax'], ['code' => 'nokey', 'type' => 'http_api', 'secret' => ''], self::PROVIDERS[0]]);
        $r = $this->resolver()->resolve();
        $this->assertSame(['aicountly_smtp'], array_column($r['providers'], 'code'));
        $this->assertStringContainsString('x, nokey', (string) $r['note']);

        $this->restart();
        array_map('unlink', glob($this->dir . '/*') ?: []);
        $this->next = ['status' => 200, 'body' => 'not json', 'error' => ''];
        $this->assertSame('env', $this->resolver()->resolve()['source']);
    }

    public function testNothingConfiguredIsHonest(): void
    {
        $this->env['EMAIL_API_KEY'] = '';
        $r = $this->resolver()->resolve();
        $this->assertSame('none', $r['source']);
        $this->assertSame([], $r['providers']);
        $this->assertStringContainsString('EMAIL_API_KEY is not set', (string) $r['note']);
        $this->assertStringStartsWith('email: no provider configured', EmailProviderResolver::describe($r));
    }

    public function testWithoutConsoleSettingsConsoleIsNeverCalled(): void
    {
        $this->env['CONSOLE_API_URL'] = '';
        $r = $this->resolver()->resolve();
        $this->assertSame('env', $r['source']);
        $this->assertSame([], $this->calls);
    }

    public function testSummaryCarriesNamesOnly(): void
    {
        $this->answer(self::PROVIDERS);
        $s = $this->resolver()->summary();

        $this->assertSame('email via itwalk_api, fallback aicountly_smtp', $s['line']);
        $this->assertSame([['code' => 'itwalk_api', 'type' => 'http_api', 'priority' => 10, 'role' => 'primary'], ['code' => 'aicountly_smtp', 'type' => 'smtp', 'priority' => 20, 'role' => 'fallback']], $s['providers']);
        $json = (string) json_encode($s);
        foreach (['itwalk-key', 'smtp-pass', 'mail.aicountly.com', 'e.api.itwalk.in'] as $secretish) {
            $this->assertStringNotContainsString($secretish, $json);
        }
    }
}
