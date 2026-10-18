<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

/**
 * What a provider said when it took a message.
 *
 *   accepted   the provider has it and reports delivery as done (synchronous)
 *   submitted  the provider accepted it for background delivery (social-api.ai
 *              HTTP 201) — NOT yet published; the caller records it as
 *              in flight and confirms the outcome by polling getStatus()
 */
final class ProviderReceipt
{
    /** @param array<string, mixed> $meta provider-specific detail (e.g. account id, redacted response) */
    public function __construct(
        public readonly string              $providerMessageId,
        public readonly string              $status,
        public readonly \DateTimeImmutable  $acceptedAt,
        public readonly ?string             $remoteUrl = null,
        public readonly ?string             $rawResponse = null,
        public readonly array               $meta = [],
    ) {}

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
