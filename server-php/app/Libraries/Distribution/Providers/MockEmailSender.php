<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

class MockEmailSender implements EmailSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt
    {
        $scenario = $this->scenario($message->idempotencyKey);
        if ($scenario === 'rate_limit')    throw new \RuntimeException('MOCK: rate limit', 429);
        if ($scenario === 'transient_fail') throw new \RuntimeException('MOCK: transient failure', 503);
        if ($scenario === 'perm_fail')     throw new \RuntimeException('MOCK: permanent rejection', 400);
        return new ProviderReceipt(
            providerMessageId: 'mock-email-' . substr(md5($message->idempotencyKey), 0, 12),
            status:            'accepted',
            acceptedAt:        new \DateTimeImmutable(),
        );
    }

    public function sendBatch(array $messages): array
    {
        return array_map(fn($m) => $this->send($m), $messages);
    }

    public function getStatus(string $providerMessageId): ProviderStatus
    {
        return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'delivered');
    }

    public function getCapabilities(): array
    {
        return ['batch_size' => 1000, 'rate_limit' => 100, 'features' => ['tracking', 'unsubscribe_header']];
    }

    public function isEnabled(): bool { return true; }

    public function verifyCallback(array $headers, string $rawBody): bool { return true; }

    public function providerName(): string { return 'mock_email'; }

    private function scenario(string $key): string
    {
        if (str_contains($key, 'rate_limit'))    return 'rate_limit';
        if (str_contains($key, 'transient_fail')) return 'transient_fail';
        if (str_contains($key, 'perm_fail'))     return 'perm_fail';
        return 'success';
    }
}
