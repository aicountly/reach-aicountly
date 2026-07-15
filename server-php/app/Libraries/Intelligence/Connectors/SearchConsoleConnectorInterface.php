<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Libraries\Intelligence\Connectors\DTOs\MetricBatch;

interface SearchConsoleConnectorInterface
{
    public function providerName(): string;
    public function isEnabled(): bool;
    public function healthCheck(): ConnectorHealthResult;
    public function getCapabilities(): array;
    public function fetchSearchMetrics(IngestionRequest $request): MetricBatch;
    public function getSiteProperties(): array;
    public function validateSiteProperty(string $property): bool;
}
