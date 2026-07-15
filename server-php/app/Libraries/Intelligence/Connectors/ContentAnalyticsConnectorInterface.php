<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Libraries\Intelligence\Connectors\DTOs\MetricBatch;

interface ContentAnalyticsConnectorInterface
{
    public function providerName(): string;
    public function isEnabled(): bool;
    public function healthCheck(): ConnectorHealthResult;
    public function getCapabilities(): array;
    public function fetchContentMetrics(IngestionRequest $request): MetricBatch;
    public function resolvePageToIdentity(string $pagePath): ?string;
    public function listAvailableProperties(): array;
}
