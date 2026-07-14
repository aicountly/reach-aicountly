<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

final class RenderReceipt
{
    public function __construct(
        public readonly string             $providerJobId,
        public readonly \DateTimeImmutable $queuedAt,
        public readonly ?int               $estimatedDurationSeconds,
        public readonly array              $receiptRaw,
    ) {}
}
