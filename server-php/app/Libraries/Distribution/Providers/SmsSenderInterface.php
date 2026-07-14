<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

interface SmsSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt;
    public function getStatus(string $providerMessageId): ProviderStatus;
    public function getCapabilities(): array;
    public function isEnabled(): bool;
    public function verifyCallback(array $headers, string $rawBody): bool;
    public function providerName(): string;
}
