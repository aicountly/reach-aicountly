<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

final class ProviderReceipt
{
    public function __construct(
        public readonly string              $providerMessageId,
        public readonly string              $status,
        public readonly \DateTimeImmutable  $acceptedAt,
        public readonly ?string             $remoteUrl = null,
        public readonly ?string             $rawResponse = null,
    ) {}

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }
}
