<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Libraries\Email\SmtpProviderTransport;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The SMTP path end to end: a registry "smtp" provider → SmtpProviderTransport → SmtpClient
 * (Console's file, copied verbatim to app/Libraries/EmailTransport/) → a scripted local SMTP
 * server (tests/_support/Fixtures/fake_smtp_server.php). Covers STARTTLS with a throw-away
 * certificate, AUTH, multipart + dot-stuffing, campaign List-Unsubscribe headers, refusals and
 * timeouts, and that no password reaches an error.
 *
 * @internal
 */
final class SmtpProviderTransportTest extends CIUnitTestCase
{
    private string $tmp;
    private int $n = 0;

    private const MSG = [
        'from_email' => 'reach@aicountly.com',
        'from_name'  => 'Aicountly Reach',
        'to'         => 'lead@example.com',
        'subject'    => 'Campaign — नमस्ते',
        'html'       => "<p>Hello</p>\n.\n<p>footer</p>",
        'text'       => "Hello\n.\nfooter",
        'headers'    => ['List-Unsubscribe' => '<https://reach.example.test/api/v1/public/unsubscribe?t=0&e=x&s=y>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/reach-smtp-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $scenario fake server script
     * @param array<string,mixed> $config   provider config overrides
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function session(array $scenario, array $config = [], string $secret = 's3cr3t-Pa55', array $msg = self::MSG, array $overrides = []): array
    {
        $this->n++;
        $scenarioFile = "{$this->tmp}/scenario{$this->n}.json";
        $portFile     = "{$this->tmp}/port{$this->n}";
        $resultFile   = "{$this->tmp}/result{$this->n}.json";
        file_put_contents($scenarioFile, json_encode($scenario));
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/_support/Fixtures/fake_smtp_server.php', $scenarioFile, $portFile, $resultFile],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$this->tmp}/err{$this->n}", 'w']],
            $pipes,
        );
        for ($i = 0; $i < 100 && ! is_file($portFile); $i++) {
            usleep(50000);
        }
        usleep(20000);
        $provider = [
            'code'   => 'aicountly_smtp',
            'type'   => 'smtp',
            'config' => $config + ['host' => '127.0.0.1', 'port' => (int) file_get_contents($portFile), 'encryption' => 'none', 'username' => 'reach@aicountly.com', 'timeout' => 5],
            'secret' => $secret,
        ];
        $answer = (new SmtpProviderTransport(null, $overrides + ['helo' => 'reach.test', 'verify_peer' => false]))->send($provider, $msg);
        for ($i = 0; $i < 100 && ! is_file($resultFile); $i++) {
            usleep(50000);
        }
        proc_terminate($proc);
        proc_close($proc);

        return [$answer, json_decode((string) @file_get_contents($resultFile), true) ?: []];
    }

    private function selfSignedPem(): string
    {
        $key  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr  = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents("{$this->tmp}/cert.pem", $certPem . $keyPem);

        return "{$this->tmp}/cert.pem";
    }

    public function testDeliversMultipartWithUnsubscribeHeaders(): void
    {
        [$a, $srv] = $this->session(['extensions' => ['AUTH PLAIN LOGIN'], 'auth_user' => 'reach@aicountly.com', 'auth_pass' => 's3cr3t-Pa55']);

        $this->assertTrue($a['ok'], (string) ($a['error'] ?? ''));
        $this->assertStringEndsWith('@aicountly.com', (string) $a['id']);
        $this->assertSame('PLAIN', $srv['auth']);
        $data = (string) $srv['data'];
        $this->assertStringContainsString('multipart/alternative', $data);
        $this->assertStringContainsString("\r\n..\r\n", $data, 'dot-stuffed');
        $this->assertStringContainsString('List-Unsubscribe: <https://reach.example.test/api/v1/public/unsubscribe?t=0&e=x&s=y>', $data);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $data);
        $this->assertStringContainsString('Subject: =?UTF-8?B?', $data);
        $this->assertContains('RCPT TO:<lead@example.com>', $srv['commands']);
    }

    public function testStartTlsThenAuthOverTls(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('openssl extension missing');
        }
        [$a, $srv] = $this->session(['extensions' => ['STARTTLS', 'AUTH PLAIN'], 'cert' => $this->selfSignedPem(), 'auth_user' => 'reach@aicountly.com', 'auth_pass' => 's3cr3t-Pa55'], ['encryption' => 'tls']);

        $this->assertTrue($a['ok'], (string) ($a['error'] ?? ''));
        $this->assertTrue($srv['tls']);
        $this->assertCount(2, array_filter($srv['commands'], static fn ($c) => str_starts_with((string) $c, 'EHLO')));
    }

    public function testRefusesToDowngradeWhenStartTlsIsMissing(): void
    {
        [$a, $srv] = $this->session(['extensions' => ['AUTH PLAIN']], ['encryption' => 'tls']);

        $this->assertFalse($a['ok']);
        $this->assertStringContainsString('STARTTLS', (string) $a['error']);
        $this->assertNotContains('AUTH PLAIN [creds]', $srv['commands']);
    }

    public function testBadPasswordFailsWithoutLeakingIt(): void
    {
        [$a, $srv] = $this->session(['extensions' => ['AUTH LOGIN'], 'auth_user' => 'reach@aicountly.com', 'auth_pass' => 'right'], [], 's3cr3t-Pa55');

        $this->assertFalse($a['ok']);
        $this->assertStringContainsString('535', (string) $a['error']);
        $this->assertStringNotContainsString('s3cr3t', (string) $a['error']);
        $this->assertNotContains('DATA', $srv['commands']);
    }

    public function testRejectedRecipientOrDataIsNotSent(): void
    {
        [$a, $srv] = $this->session(['extensions' => [], 'reject_rcpt' => ['lead@example.com']], ['username' => '']);
        $this->assertFalse($a['ok']);
        $this->assertStringContainsString('550', (string) $a['error']);
        $this->assertNotContains('DATA', $srv['commands']);

        [$a] = $this->session(['extensions' => [], 'data_code' => 554], ['username' => '']);
        $this->assertFalse($a['ok']);
        $this->assertStringContainsString('554', (string) $a['error']);
    }

    public function testSilentServerTimesOut(): void
    {
        $t0 = microtime(true);
        [$a] = $this->session(['extensions' => [], 'stall_on' => 'DATA'], ['username' => '', 'timeout' => 1]);

        $this->assertFalse($a['ok']);
        $this->assertStringContainsString('timed out', (string) $a['error']);
        $this->assertLessThan(10, microtime(true) - $t0);
    }
}
