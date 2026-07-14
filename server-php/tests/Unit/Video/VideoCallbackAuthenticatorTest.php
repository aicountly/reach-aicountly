<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Libraries\Video\VideoCallbackAuthenticator;
use App\Models\Video\VideoProviderEventModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Video\VideoCallbackAuthenticator
 */
class VideoCallbackAuthenticatorTest extends CIUnitTestCase
{
    private const HMAC_KEY = 'test-secret-key-for-callbacks';
    private const PROVIDER = 'render_provider';

    private function buildSignature(string $body, string $key): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $key);
    }

    private function mockEventModel(bool $isDuplicate = false): VideoProviderEventModel
    {
        $mock = $this->createMock(VideoProviderEventModel::class);
        $mock->method('isDuplicate')->willReturn($isDuplicate);
        $mock->method('record')->willReturn(true);
        return $mock;
    }

    public function testValidSignaturePassesVerification(): void
    {
        $body      = '{"status":"rendered","job_id":"abc123"}';
        $timestamp = (string) time();
        $signature = $this->buildSignature($body, self::HMAC_KEY);
        $eventId   = 'event-' . uniqid();

        $auth = new VideoCallbackAuthenticator($this->mockEventModel(false));
        $result = $auth->verify($body, self::HMAC_KEY, $signature, (int) $timestamp, $eventId, self::PROVIDER);

        $this->assertTrue($result['ok'], 'Valid HMAC should pass verification');
    }

    public function testInvalidSignatureFailsVerification(): void
    {
        $body      = '{"status":"rendered"}';
        $timestamp = (string) time();
        $eventId   = 'event-abc';

        $auth = new VideoCallbackAuthenticator($this->mockEventModel(false));
        $result = $auth->verify($body, self::HMAC_KEY, 'invalid-signature', (int) $timestamp, $eventId, self::PROVIDER);

        $this->assertFalse($result['ok'], 'Invalid HMAC should fail verification');
    }

    public function testExpiredTimestampFailsVerification(): void
    {
        $body      = '{"status":"rendered"}';
        $oldTs     = time() - 600; // 10 minutes old — exceeds 5 minute tolerance
        $signature = $this->buildSignature($body, self::HMAC_KEY);
        $eventId   = 'event-old';

        $auth = new VideoCallbackAuthenticator($this->mockEventModel(false));
        $result = $auth->verify($body, self::HMAC_KEY, $signature, $oldTs, $eventId, self::PROVIDER);

        $this->assertFalse($result['ok'], 'Expired timestamp should fail verification');
    }

    public function testDuplicateEventIdFailsReplayGuard(): void
    {
        $body      = '{"status":"rendered"}';
        $timestamp = time();
        $signature = $this->buildSignature($body, self::HMAC_KEY);
        $eventId   = 'event-duplicate';

        // Model says this event is a duplicate
        $auth = new VideoCallbackAuthenticator($this->mockEventModel(isDuplicate: true));
        $result = $auth->verify($body, self::HMAC_KEY, $signature, (int) $timestamp, $eventId, self::PROVIDER);

        $this->assertFalse($result['ok'], 'Duplicate event ID should fail replay guard');
    }

    public function testWrongSignaturePrefixIsRejected(): void
    {
        $body      = '{"status":"rendered"}';
        $timestamp = time();
        // Intentionally omit the sha256= prefix
        $signature = hash_hmac('sha256', $body, self::HMAC_KEY);
        $eventId   = 'event-bad-format';

        $auth = new VideoCallbackAuthenticator($this->mockEventModel(false));
        $result = $auth->verify($body, self::HMAC_KEY, $signature, $timestamp, $eventId, self::PROVIDER);

        $this->assertFalse($result['ok'], 'Signature without sha256= prefix should be rejected');
    }

    public function testProductionRenderAdapterIsDisabledByDefault(): void
    {
        // The production adapter must throw when VIDEO_RENDER_PROVIDER is not 'production'
        $this->expectException(\LogicException::class);
        new \App\Libraries\Video\Providers\ProductionRenderAdapter();
    }

    public function testProviderFactoryReturnsMockByDefault(): void
    {
        $provider = \App\Libraries\Video\Providers\VideoProviderFactory::makeRenderProvider();
        $this->assertInstanceOf(
            \App\Libraries\Video\Providers\MockRenderProvider::class,
            $provider,
            'Default render provider must be MockRenderProvider in test/non-production environments'
        );
    }
}
