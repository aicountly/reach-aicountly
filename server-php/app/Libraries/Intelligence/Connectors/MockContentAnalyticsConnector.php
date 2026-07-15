<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Libraries\Intelligence\Connectors\DTOs\MetricBatch;

class MockContentAnalyticsConnector implements ContentAnalyticsConnectorInterface
{
    private bool  $enabled;
    private bool  $shouldFail;
    private array $pageMap;
    private array $callLog = [];

    public function __construct(bool $enabled = true, bool $shouldFail = false, array $pageMap = [])
    {
        $this->enabled    = $enabled;
        $this->shouldFail = $shouldFail;
        $this->pageMap    = $pageMap ?: ['/blog/post-1' => 'https://example.com/blog/post-1'];
    }

    public function providerName(): string { return 'mock_ga4'; }
    public function isEnabled(): bool      { return $this->enabled; }

    public function healthCheck(): ConnectorHealthResult
    {
        $this->callLog[] = ['method' => 'healthCheck'];
        if ($this->shouldFail) {
            return ConnectorHealthResult::failing('mock ga4 failure', 'AuthFailure', 403);
        }
        return ConnectorHealthResult::healthy(35);
    }

    public function getCapabilities(): array
    {
        return [
            'backfill_days'        => 90,
            'batch_size'           => 10000,
            'supported_dimensions' => ['pagePath', 'sessionSource', 'sessionMedium'],
            'provider_name'        => 'mock_ga4',
        ];
    }

    public function fetchContentMetrics(IngestionRequest $request): MetricBatch
    {
        $this->callLog[] = ['method' => 'fetchContentMetrics', 'request' => $request];

        if ($this->shouldFail) {
            throw new \RuntimeException('mock_ga4: simulated failure');
        }

        $from = new \DateTimeImmutable($request->dateFrom);
        $to   = new \DateTimeImmutable($request->dateTo);
        $rows = [];

        foreach ($this->pageMap as $path => $url) {
            for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
                $rows[] = [
                    'metric_date'              => $d->format('Y-m-d'),
                    'page_url'                 => $url,
                    'page_path'                => $path,
                    'source'                   => 'google',
                    'medium'                   => 'organic',
                    'sessions'                 => 45,
                    'users'                    => 38,
                    'new_users'                => 22,
                    'engaged_sessions'         => 30,
                    'engagement_rate'          => 0.667,
                    'avg_engagement_time_secs' => 124.5,
                    'entrances'                => 40,
                    'page_views'               => 52,
                ];
            }
        }

        return new MetricBatch(
            rows: $rows,
            nextCursorState: null,
            providerFreshnessAt: date('Y-m-d H:i:s'),
            rowCount: count($rows),
            isComplete: true,
        );
    }

    public function resolvePageToIdentity(string $pagePath): ?string
    {
        return $this->pageMap[$pagePath] ?? null;
    }

    public function listAvailableProperties(): array
    {
        return ['properties/123456789'];
    }

    public function getCallLog(): array { return $this->callLog; }
}
