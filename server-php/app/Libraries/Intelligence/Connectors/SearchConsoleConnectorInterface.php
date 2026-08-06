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

    /** The property this connector instance currently reads. */
    public function siteProperty(): string;

    /** A connector bound to a stored connection's property rather than the env default. */
    public function forSiteProperty(string $siteProperty): self;

    public function healthCheck(): ConnectorHealthResult;
    public function getCapabilities(): array;
    public function fetchSearchMetrics(IngestionRequest $request): MetricBatch;
    public function getSiteProperties(): array;
    public function validateSiteProperty(string $property): bool;
}
