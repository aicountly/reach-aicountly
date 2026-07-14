<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\DistributionCallbackAuthenticator;
use App\Libraries\Distribution\Providers\DistributionCallbackAuthenticator as Alias;
use App\Models\Distribution\CampaignProviderEventModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DistributionCallbackAuthenticatorTest extends CIUnitTestCase
{
    private const SECRET = 'test-secret-key-for-unit-tests';

    private function makeAuth(bool $isDuplicate = false): DistributionCallbackAuthenticator
    {
        $model = $this->createMock(CampaignProviderEventModel::class);
        $model->method('isDuplicate')->willReturn($isDuplicate);
        return new DistributionCallbackAuthenticator($model);
    }

    private function headers(string $body, int $ts = 0, string $secret = self::SECRET): array
    {
        $timestamp = $ts === 0 ? time() : $ts;
        return [
            'x-distribution-signature' => 'sha256=' . hash_hmac('sha256', $body, $secret),
            'x-distribution-timestamp' => (string) $timestamp,
        ];
    }

    public function testValidSignatureReturnsTrue(): void
    {
        $body    = '{"event":"delivery"}';
        $headers = $this->headers($body);
        $auth    = $this->makeAuth();
        $this->assertTrue(
            $auth->verify($headers, $body, self::SECRET, 'mock_email', 1, 'evt-001')
        );
    }

    public function testInvalidSignatureReturnsFalse(): void
    {
        $body    = '{"event":"delivery"}';
        $headers = $this->headers($body, 0, 'wrong-secret');
        $auth    = $this->makeAuth();
        $this->assertFalse(
            $auth->verify($headers, $body, self::SECRET, 'mock_email', 1, 'evt-002')
        );
    }

    public function testMissingSignaturePrefixReturnsFalse(): void
    {
        $body    = '{"event":"delivery"}';
        $headers = ['x-distribution-signature' => hash_hmac('sha256', $body, self::SECRET)];
        $auth    = $this->makeAuth();
        $this->assertFalse(
            $auth->verify($headers, $body, self::SECRET, 'mock_email', 1, 'evt-003')
        );
    }

    public function testTimestampTooOldReturnsFalse(): void
    {
        $body    = '{"event":"delivery"}';
        $staleTs = time() - 400; // > 300s tolerance
        $headers = $this->headers($body, $staleTs);
        $auth    = $this->makeAuth();
        $this->assertFalse(
            $auth->verify($headers, $body, self::SECRET, 'mock_email', 1, 'evt-004')
        );
    }

    public function testReplayEventReturnsFalse(): void
    {
        $body    = '{"event":"delivery"}';
        $headers = $this->headers($body);
        $auth    = $this->makeAuth(isDuplicate: true);
        $this->assertFalse(
            $auth->verify($headers, $body, self::SECRET, 'mock_email', 1, 'evt-dup-001')
        );
    }

    public function testNullProviderEventIdSkipsReplayCheck(): void
    {
        $body    = '{"event":"delivery"}';
        $headers = $this->headers($body);
        // isDuplicate would return true, but providerEventId is null so it's never called
        $auth    = $this->makeAuth(isDuplicate: true);
        $this->assertTrue(
            $auth->verify($headers, $body, self::SECRET, 'mock_email', 1, null)
        );
    }

    public function testComputeSignatureProducesExpectedFormat(): void
    {
        $sig = DistributionCallbackAuthenticator::computeSignature('body', self::SECRET);
        $this->assertStringStartsWith('sha256=', $sig);
        $this->assertSame(71, strlen($sig)); // 'sha256=' (7) + 64 hex chars
    }
}
