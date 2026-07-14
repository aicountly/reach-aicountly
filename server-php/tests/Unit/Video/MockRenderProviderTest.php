<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Libraries\Video\Providers\MockRenderProvider;
use App\Libraries\Video\Providers\RenderReceipt;
use App\Libraries\Video\Providers\RenderStatus;
use App\Exceptions\Video\ProviderRateLimitException;
use App\Exceptions\Video\ProviderInvalidRequestException;
use CodeIgniter\Test\CIUnitTestCase;

final class MockRenderProviderTest extends CIUnitTestCase
{
    private MockRenderProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new MockRenderProvider();
    }

    private function job(string $scenario = 'success', string $idempotencyKey = ''): array
    {
        return [
            'render_job_uuid'    => 'test-uuid-' . uniqid(),
            'project_uuid'       => 'proj-uuid',
            'script_version_uuid' => 'script-uuid',
            'render_profile'     => ['mock_scenario' => $scenario, 'resolution' => '1920x1080'],
            'asset_urls'         => [],
            'idempotency_key'    => $idempotencyKey,
        ];
    }

    public function testSuccessScenarioReturnsReceipt(): void
    {
        $receipt = $this->provider->queue($this->job('success'));
        $this->assertInstanceOf(RenderReceipt::class, $receipt);
        $this->assertStringStartsWith('mock-render-', $receipt->providerJobId);
    }

    public function testRateLimitScenarioThrows(): void
    {
        $this->expectException(ProviderRateLimitException::class);
        $this->provider->queue($this->job('rate_limit'));
    }

    public function testInvalidRequestScenarioThrows(): void
    {
        $this->expectException(ProviderInvalidRequestException::class);
        $this->provider->queue($this->job('invalid_request'));
    }

    public function testIdempotencyReturnsSameReceipt(): void
    {
        $key     = 'idempotency-key-abc';
        $receipt1 = $this->provider->queue($this->job('success', $key));
        $receipt2 = $this->provider->queue($this->job('success', $key));
        $this->assertSame($receipt1->providerJobId, $receipt2->providerJobId);
    }

    public function testStatusSuccessReturnsRendered(): void
    {
        $receipt = $this->provider->queue($this->job('success', 'key-' . uniqid()));
        $status  = $this->provider->status($receipt->providerJobId);
        $this->assertInstanceOf(RenderStatus::class, $status);
        $this->assertSame('rendered', $status->state);
        $this->assertNotNull($status->outputUrl);
    }

    public function testStatusTimeoutReturnsRendering(): void
    {
        $receipt = $this->provider->queue($this->job('timeout', 'key-' . uniqid()));
        $status  = $this->provider->status($receipt->providerJobId);
        $this->assertSame('rendering', $status->state);
    }

    public function testStatusErrorReturnsFailed(): void
    {
        $receipt = $this->provider->queue($this->job('error', 'key-' . uniqid()));
        $status  = $this->provider->status($receipt->providerJobId);
        $this->assertSame('failed', $status->state);
        $this->assertSame('mock_provider_error', $status->failureReason);
    }

    public function testCancelReturnsTrue(): void
    {
        $receipt = $this->provider->queue($this->job('success'));
        $this->assertTrue($this->provider->cancel($receipt->providerJobId));
    }

    public function testGetCapabilitiesReturnsExpectedKeys(): void
    {
        $caps = $this->provider->getCapabilities();
        $this->assertArrayHasKey('max_resolution', $caps);
        $this->assertArrayHasKey('supported_formats', $caps);
        $this->assertArrayHasKey('supports_polling', $caps);
    }
}
