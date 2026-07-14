<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

final class ProviderStatus
{
    public function __construct(
        public readonly string              $providerMessageId,
        public readonly string              $normalisedStatus,
        public readonly ?\DateTimeImmutable $statusAt = null,
        public readonly ?string             $failureClass = null,
        public readonly array               $raw = [],
    ) {}
}
