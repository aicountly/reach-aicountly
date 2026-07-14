<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

final class RenderStatus
{
    public function __construct(
        public readonly string              $state,
        public readonly ?int                $progressPct,
        public readonly ?string             $failureReason,
        public readonly ?string             $outputUrl,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly array               $statusRaw,
    ) {}
}
