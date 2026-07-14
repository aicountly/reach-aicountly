<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

interface SocialPublisherInterface
{
    public function publish(ChannelMessage $message, string $platform): ProviderReceipt;
    public function getStatus(string $providerPostId): ProviderStatus;
    public function withdraw(string $providerPostId): bool;
    public function getCapabilities(): array;
    public function isEnabled(): bool;
    public function verifyCallback(array $headers, string $rawBody): bool;
    public function providerName(): string;
}
