<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Libraries\Distribution\CampaignReadinessService;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\GatewayEmailSender;
use App\Libraries\Email\EmailDispatcher;
use App\Libraries\Email\EmailProviderResolver;
use App\Libraries\Gateways\Mailer;
use App\Libraries\Reports\DailyReportBuilder;
use App\Libraries\Reports\DailyReportRenderer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Reach's mail paths on the dispatcher: Mailer facade (daily report, alerts), the campaign
 * GatewayEmailSender (body unchanged, List-Unsubscribe headers, honest 502), the readiness report
 * (provider names, never secrets) and the daily report's pipeline-health email line.
 *
 * @internal
 */
final class ReachEmailWiringTest extends CIUnitTestCase
{
    private string $dir;
    /** @var list<array{provider:string, msg:array<string,mixed>}> */
    public array $log = [];
    /** @var array<string, array<string,mixed>> */
    public array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/reach-wiring-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0700, true);
        EmailProviderResolver::clearMemo();
        $this->install(['status' => 200, 'body' => (string) json_encode(['data' => ['ttl_seconds' => 300, 'providers' => EmailProviderResolverTest::PROVIDERS]]), 'error' => '']);
    }

    protected function tearDown(): void
    {
        EmailDispatcher::setInstance(null);
        EmailProviderResolver::clearMemo();
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array{status:int, body:string, error:string} $console */
    private function install(array $console, string $envKey = ''): void
    {
        EmailProviderResolver::clearMemo();
        array_map('unlink', glob($this->dir . '/*') ?: []);
        $resolver = new EmailProviderResolver(
            static fn (): array => $console,
            $this->dir,
            static fn (): int => 2_000_000,
            static fn (string $k): string => ['CONSOLE_API_URL' => 'https://console.test/api', 'CONSOLE_API_TOKEN' => 't', 'EMAIL_API_KEY' => $envKey][$k] ?? '',
        );
        $test = $this;
        EmailDispatcher::setInstance(new EmailDispatcher($resolver, static fn (array $p): object => new class ($test) {
            public function __construct(private ReachEmailWiringTest $t)
            {
            }

            public function send(array $provider, array $msg): array
            {
                $this->t->log[] = ['provider' => $provider['code'], 'msg' => $msg];

                return $this->t->answers[$provider['code']] ?? ['ok' => false, 'error' => 'unscripted'];
            }
        }));
    }

    public function testMailerFacadeReportsWhoSentIt(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => false, 'error' => 'HTTP 503'], 'aicountly_smtp' => ['ok' => true, 'id' => 'abc@aicountly.com', 'mode' => 'smtp']];

        $this->assertTrue(Mailer::isConfigured());
        $this->assertTrue(Mailer::send('pno@aicountly.com', 'Daily report', '<p>r</p>', 'r'));
        $this->assertSame('aicountly_smtp', Mailer::getLastProvider());
        $this->assertSame('abc@aicountly.com', Mailer::getLastMessageId());
        $this->assertSame('smtp', Mailer::getLastSendMode());
        $this->assertNull(Mailer::getLastError());
        $this->assertSame('email via itwalk_api, fallback aicountly_smtp', Mailer::providerSummary()['line']);

        $this->answers = ['itwalk_api' => ['ok' => false, 'error' => 'HTTP 503'], 'aicountly_smtp' => ['ok' => false, 'error' => 'SMTP 451 at RCPT TO']];
        $this->assertFalse(Mailer::send('pno@aicountly.com', 'Daily report', '<p>r</p>'));
        $this->assertStringContainsString('itwalk_api: HTTP 503; aicountly_smtp: SMTP 451', (string) Mailer::getLastError());
        $this->assertNull(Mailer::getLastProvider());
    }

    public function testResendOptionReachesTheDispatcher(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => true], 'aicountly_smtp' => ['ok' => true]];
        Mailer::send('a@example.com', 'S', '<p>x</p>', null, ['resend' => true]);
        $this->assertSame('aicountly_smtp', Mailer::getLastProvider());
    }

    public function testCampaignSenderKeepsTheBodyAndAddsUnsubscribeHeaders(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => true, 'id' => 'BULK1', 'mode' => 'multipart']];
        $html = '<p>Offer</p><p style="font-size:12px">Unsubscribe: <a href="https://reach.example.test/u">link</a> · 1 Street</p>';
        $receipt = (new GatewayEmailSender())->send(new ChannelMessage(
            idempotencyKey: 'k1',
            recipientAddress: 'lead@example.com',
            content: $html,
            metadata: ['subject' => 'Offer', 'body_text' => "Offer\nUnsubscribe: https://reach.example.test/u", 'unsubscribe_url' => 'https://reach.example.test/u'],
        ));

        $this->assertSame('BULK1', $receipt->providerMessageId);
        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('itwalk_api', json_decode((string) $receipt->rawResponse, true)['provider']);
        $msg = $this->log[0]['msg'];
        $this->assertSame($html, $msg['html'], 'footer/unsubscribe body passed through unchanged');
        $this->assertSame("Offer\nUnsubscribe: https://reach.example.test/u", $msg['text']);
        $this->assertSame('<https://reach.example.test/u>', $msg['headers']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $msg['headers']['List-Unsubscribe-Post']);
        $this->assertSame('console_email', (new GatewayEmailSender())->providerName());
        $this->assertSame(['itwalk_api', 'aicountly_smtp'], (new GatewayEmailSender())->getCapabilities()['providers']);
    }

    public function testCampaignSenderFailsHonestly(): void
    {
        $this->answers = ['itwalk_api' => ['ok' => false, 'error' => 'HTTP 401'], 'aicountly_smtp' => ['ok' => false, 'error' => 'SMTP 535 at AUTH']];
        try {
            (new GatewayEmailSender())->send(new ChannelMessage(idempotencyKey: 'k2', recipientAddress: 'lead@example.com', content: '<p>x</p>', metadata: ['subject' => 'S']));
            $this->fail('a send no provider accepted must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertStringContainsString('itwalk_api: HTTP 401', $e->getMessage());
            $this->assertStringContainsString('aicountly_smtp: SMTP 535', $e->getMessage());
        }
    }

    public function testReadinessShowsProviderNamesNeverSecrets(): void
    {
        $report = (new CampaignReadinessService())->report();
        $email  = array_column($report['channels'], null, 'channel')['email'];

        $this->assertSame('itwalk_api → aicountly_smtp', $email['provider']);
        $this->assertSame('console', $email['provider_source']);
        $this->assertSame('email via itwalk_api, fallback aicountly_smtp', $email['provider_line']);
        $this->assertSame(['primary', 'fallback'], array_column($email['providers'], 'role'));
        $this->assertTrue(array_column($email['checks'], null, 'key')['email providers']['ok']);
        $json = (string) json_encode($report);
        foreach (['itwalk-key-123456', 'smtp-pass-XYZ', 'mail.aicountly.com'] as $secretish) {
            $this->assertStringNotContainsString($secretish, $json);
        }
    }

    public function testReadinessSaysWhenOnlyTheEnvFallbackOrNothingIsLeft(): void
    {
        $this->install(['status' => 0, 'body' => '', 'error' => 'timeout'], 'env-key-999');
        $email = array_column((new CampaignReadinessService())->report()['channels'], null, 'channel')['email'];
        $this->assertSame('env_email_api', $email['provider']);
        $this->assertStringContainsString('.env EMAIL_API_KEY last-resort provider', implode(' ', $email['reasons']));
        $this->assertStringNotContainsString('env-key-999', (string) json_encode($email));

        $this->install(['status' => 0, 'body' => '', 'error' => 'timeout']);
        $email = array_column((new CampaignReadinessService())->report()['channels'], null, 'channel')['email'];
        $this->assertSame('not_configured', $email['state']);
        $this->assertSame('none', $email['provider']);
        $this->assertFalse(array_column($email['checks'], null, 'key')['email providers']['ok']);
    }

    public function testDailyReportPipelineHealthNamesTheEmailProviders(): void
    {
        $sections = [
            'search'      => ['configured' => false, 'note' => 'n'],
            'traffic'     => ['configured' => false, 'note' => 'n'],
            'seo_tracker' => ['configured' => false, 'note' => 'n'],
            'content'     => ['configured' => true, 'counts' => ['blog' => 1], 'total' => 1],
            'vetting'     => ['configured' => false, 'note' => 'n'],
            'pipeline'    => ['configured' => true, 'email' => 'email via itwalk_api, fallback aicountly_smtp', 'failed_jobs_24h' => 0, 'dead_letters' => 0, 'stalled_jobs' => 0, 'pending_over_1h' => 0],
        ];
        $sections['todo'] = ['configured' => true, 'items' => DailyReportBuilder::todo($sections)];
        $summary = ['report_date' => '2026-10-18', 'generated_at' => '2026-10-18 08:00:00+05:30', 'timezone' => 'Asia/Kolkata', 'sections' => $sections];

        $this->assertStringContainsString('Email: email via itwalk_api, fallback aicountly_smtp', DailyReportRenderer::text($summary));
        $this->assertStringContainsString('Email: email via itwalk_api, fallback aicountly_smtp', DailyReportRenderer::html($summary));

        $sections['pipeline'] = ['configured' => true, 'email' => 'email: no provider configured — Console unreachable (timeout); EMAIL_API_KEY is not set', 'note' => 'Job queue table not found.'];
        $items = DailyReportBuilder::todo($sections);
        $this->assertStringContainsString('No email provider is configured for Reach', implode("\n", $items));
        $summary['sections'] = $sections + ['todo' => ['configured' => true, 'items' => $items]];
        $this->assertStringContainsString('Email: email: no provider configured', DailyReportRenderer::html($summary), 'shown even when the job table is missing');
    }
}
