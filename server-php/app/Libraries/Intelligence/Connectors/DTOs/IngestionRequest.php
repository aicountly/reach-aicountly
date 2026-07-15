<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors\DTOs;

final class IngestionRequest
{
    public function __construct(
        public readonly int    $connectionId,
        public readonly string $streamType,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly array  $dimensions = [],
        public readonly int    $batchSize = 25000,
        public readonly ?array $cursorState = null,
        public readonly bool   $isBackfill = false,
    ) {}
}
