<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

interface EmailSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt;

    /** @param ChannelMessage[] $messages @return ProviderReceipt[] */
    public function sendBatch(array $messages): array;

    public function getStatus(string $providerMessageId): ProviderStatus;
    public function getCapabilities(): array;
    public function isEnabled(): bool;
    public function verifyCallback(array $headers, string $rawBody): bool;
    public function providerName(): string;
}
