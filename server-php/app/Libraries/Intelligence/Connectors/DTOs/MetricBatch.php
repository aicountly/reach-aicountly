<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors\DTOs;

final class MetricBatch
{
    public function __construct(
        public readonly array   $rows,
        public readonly ?array  $nextCursorState,
        public readonly string  $providerFreshnessAt,
        public readonly int     $rowCount,
        public readonly bool    $isComplete,
        public readonly ?string $warningMessage = null,
    ) {}

    public static function empty(): self
    {
        return new self(
            rows: [],
            nextCursorState: null,
            providerFreshnessAt: date('Y-m-d H:i:s'),
            rowCount: 0,
            isComplete: true,
        );
    }
}
