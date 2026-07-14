<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

use App\Exceptions\Video\ProviderRateLimitException;
use App\Exceptions\Video\ProviderInvalidRequestException;

/**
 * Deterministic mock render provider for use in CI and testing.
 *
 * Scenario is selected via $job['render_profile']['mock_scenario']
 * (defaults to 'success').
 *
 * No network calls are made.
 */
class MockRenderProvider implements RenderProviderInterface
{
    private array $queuedJobs = [];

    public function queue(array $job): RenderReceipt
    {
        $scenario       = $job['render_profile']['mock_scenario'] ?? 'success';
        $idempotencyKey = $job['idempotency_key'] ?? '';

        if ($scenario === 'rate_limit') {
            throw new ProviderRateLimitException(
                'Mock rate limit exceeded',
                ['provider' => 'mock', 'operation' => 'queue'],
                60
            );
        }

        if ($scenario === 'invalid_request') {
            throw new ProviderInvalidRequestException(
                'Mock invalid request',
                ['provider' => 'mock', 'operation' => 'queue']
            );
        }

        if ($idempotencyKey !== '' && isset($this->queuedJobs[$idempotencyKey])) {
            return $this->queuedJobs[$idempotencyKey];
        }

        $providerJobId = 'mock-render-' . ($job['render_job_uuid'] ?? uniqid('job-'));
        $receipt       = new RenderReceipt(
            providerJobId:           $providerJobId,
            queuedAt:                new \DateTimeImmutable(),
            estimatedDurationSeconds: 30,
            receiptRaw:              [
                'mock'         => true,
                'scenario'     => $scenario,
                'provider_job' => $providerJobId,
            ],
        );

        if ($idempotencyKey !== '') {
            $this->queuedJobs[$idempotencyKey] = $receipt;
        }

        return $receipt;
    }

    public function status(string $providerJobId): RenderStatus
    {
        $scenario = $this->scenarioForJob($providerJobId);

        return match ($scenario) {
            'error'   => new RenderStatus('failed', null, 'mock_provider_error', null, new \DateTimeImmutable(), ['mock' => true]),
            'timeout' => new RenderStatus('rendering', 50, null, null, null, ['mock' => true]),
            default   => new RenderStatus(
                'rendered',
                100,
                null,
                "mock://rendered/{$providerJobId}",
                new \DateTimeImmutable(),
                ['mock' => true, 'output_url' => "mock://rendered/{$providerJobId}"]
            ),
        };
    }

    public function cancel(string $providerJobId): bool
    {
        $scenario = $this->scenarioForJob($providerJobId);
        return $scenario !== 'rendered';
    }

    public function getCapabilities(): array
    {
        return [
            'max_resolution'      => '3840x2160',
            'supported_formats'   => ['mp4', 'webm'],
            'max_duration_secs'   => 3600,
            'max_asset_bytes'     => 5368709120,
            'supports_callback'   => false,
            'supports_polling'    => true,
        ];
    }

    private function scenarioForJob(string $providerJobId): string
    {
        foreach ($this->queuedJobs as $receipt) {
            if ($receipt->providerJobId === $providerJobId) {
                return $receipt->receiptRaw['scenario'] ?? 'success';
            }
        }
        return 'success';
    }
}
