<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;

interface IndexNowConnectorInterface
{
    public function providerName(): string;
    public function isEnabled(): bool;
    public function healthCheck(): ConnectorHealthResult;
    public function getCapabilities(): array;
    public function submitUrl(string $url, string $key): array;
    public function submitBatch(array $urls, string $key): array;
    public function validateKeyLocation(string $keyUrl): bool;
}
