<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Libraries\Intelligence\Connectors\DTOs\MetricBatch;

class MockSearchConsoleConnector implements SearchConsoleConnectorInterface
{
    private bool $enabled;
    private bool $shouldFail;
    private int  $quotaSimulationAfterRows;
    private array $callLog = [];

    public function __construct(bool $enabled = true, bool $shouldFail = false, int $quotaSimulationAfterRows = 0)
    {
        $this->enabled                  = $enabled;
        $this->shouldFail               = $shouldFail;
        $this->quotaSimulationAfterRows = $quotaSimulationAfterRows;
    }

    public function providerName(): string { return 'mock_gsc'; }
    public function isEnabled(): bool      { return $this->enabled; }

    public function healthCheck(): ConnectorHealthResult
    {
        $this->callLog[] = ['method' => 'healthCheck'];
        if ($this->shouldFail) {
            return ConnectorHealthResult::failing('mock auth failure', 'AuthFailure', 401);
        }
        return ConnectorHealthResult::healthy(42);
    }

    public function getCapabilities(): array
    {
        return [
            'max_date_range_days'      => 490,
            'batch_size'               => 25000,
            'supported_dimensions'     => ['query', 'page', 'device', 'country'],
            'supports_pagination'      => true,
            'rate_limit_per_minute'    => 200,
            'provider_name'            => 'mock_gsc',
        ];
    }

    public function fetchSearchMetrics(IngestionRequest $request): MetricBatch
    {
        $this->callLog[] = ['method' => 'fetchSearchMetrics', 'request' => $request];

        if ($this->shouldFail) {
            throw new \RuntimeException('mock_gsc: simulated failure');
        }

        $rows = [];
        $from = new \DateTimeImmutable($request->dateFrom);
        $to   = new \DateTimeImmutable($request->dateTo);

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            if ($this->quotaSimulationAfterRows > 0 && count($rows) >= $this->quotaSimulationAfterRows) {
                break;
            }
            $rows[] = [
                'metric_date'  => $d->format('Y-m-d'),
                'page_url'     => 'https://example.com/blog/post-1',
                'query'        => 'accounting software',
                'device'       => 'DESKTOP',
                'country'      => 'IN',
                'clicks'       => 12,
                'impressions'  => 340,
                'ctr'          => 0.035,
                'avg_position' => 4.2,
            ];
        }

        return new MetricBatch(
            rows: $rows,
            nextCursorState: null,
            providerFreshnessAt: date('Y-m-d H:i:s'),
            rowCount: count($rows),
            isComplete: true,
        );
    }

    public function getSiteProperties(): array
    {
        return ['sc-domain:example.com', 'https://example.com/'];
    }

    public function validateSiteProperty(string $property): bool
    {
        return in_array($property, $this->getSiteProperties(), true);
    }

    public function getCallLog(): array { return $this->callLog; }
    public function clearCallLog(): void { $this->callLog = []; }
}
