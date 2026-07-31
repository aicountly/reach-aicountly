<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\Connectors\GoogleServiceAccountToken;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Credential-inspection coverage. Never performs the token exchange, so no
 * network access is required.
 */
final class GoogleServiceAccountTokenTest extends CIUnitTestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        GoogleServiceAccountToken::clearCache();
        parent::tearDown();
    }

    public function testInspectReportsUnconfiguredPath(): void
    {
        $result = GoogleServiceAccountToken::inspect('');

        $this->assertFalse($result['path_configured']);
        $this->assertFalse($result['file_exists']);
        $this->assertFalse($result['parsable']);
        $this->assertNull($result['client_email']);
    }

    public function testInspectReportsMissingFile(): void
    {
        $result = GoogleServiceAccountToken::inspect('/tmp/missing-key-' . bin2hex(random_bytes(6)) . '.json');

        $this->assertTrue($result['path_configured']);
        $this->assertFalse($result['file_exists']);
        $this->assertFalse($result['parsable']);
    }

    public function testInspectReportsUnparsableCredentialFile(): void
    {
        $path = $this->writeTempFile('{"not":"a service account"}');

        $result = GoogleServiceAccountToken::inspect($path);

        $this->assertTrue($result['file_readable']);
        $this->assertFalse($result['parsable']);
        $this->assertNull($result['client_email']);
    }

    public function testInspectExtractsClientEmailFromValidShapedKey(): void
    {
        $path = $this->writeTempFile(json_encode([
            'client_email' => 'reach-gsc@example.iam.gserviceaccount.com',
            'private_key'  => "-----BEGIN PRIVATE KEY-----\nnot-a-real-key\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR));

        $result = GoogleServiceAccountToken::inspect($path);

        $this->assertTrue($result['parsable']);
        $this->assertSame('reach-gsc@example.iam.gserviceaccount.com', $result['client_email']);
    }

    public function testGetReturnsNullForUnreadablePathWithoutNetworkCall(): void
    {
        $this->assertNull(GoogleServiceAccountToken::get('', 'https://example.com/scope'));
        $this->assertNull(
            GoogleServiceAccountToken::get('/tmp/nope-' . bin2hex(random_bytes(6)), 'https://example.com/scope'),
        );
    }

    public function testGetReturnsNullWhenKeyFileLacksRequiredFields(): void
    {
        $path = $this->writeTempFile('{"client_email":"a@b.com"}');

        $this->assertNull(GoogleServiceAccountToken::get($path, 'https://example.com/scope'));
    }

    public function testGetReturnsNullWhenPrivateKeyIsNotUsable(): void
    {
        $path = $this->writeTempFile(json_encode([
            'client_email' => 'reach-gsc@example.iam.gserviceaccount.com',
            'private_key'  => 'this-is-not-a-pem-key',
        ], JSON_THROW_ON_ERROR));

        $this->assertNull(GoogleServiceAccountToken::get($path, 'https://example.com/scope'));
    }

    private function writeTempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/reach-gsc-test-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
