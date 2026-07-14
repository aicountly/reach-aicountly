<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

class MockWhatsAppSender implements WhatsAppSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt
    {
        $scenario = $this->scenario($message->idempotencyKey);
        if ($scenario === 'rate_limit')    throw new \RuntimeException('MOCK: rate limit', 429);
        if ($scenario === 'transient_fail') throw new \RuntimeException('MOCK: transient failure', 503);
        if ($scenario === 'perm_fail')     throw new \RuntimeException('MOCK: permanent rejection', 400);
        return new ProviderReceipt(
            providerMessageId: 'mock-wa-' . substr(md5($message->idempotencyKey), 0, 12),
            status:            'accepted',
            acceptedAt:        new \DateTimeImmutable(),
        );
    }

    public function getTemplates(): array
    {
        return [
            ['id' => 'mock_template_1', 'name' => 'hello_world', 'status' => 'approved', 'language' => 'en'],
        ];
    }

    public function getTemplateStatus(string $templateId): array
    {
        return ['id' => $templateId, 'status' => 'approved'];
    }

    public function getStatus(string $providerMessageId): ProviderStatus
    {
        return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'delivered');
    }

    public function getCapabilities(): array
    {
        return ['template_required' => true, 'messaging_window' => 24, 'media_types' => ['image', 'document', 'video']];
    }

    public function isEnabled(): bool { return true; }

    public function verifyCallback(array $headers, string $rawBody): bool { return true; }

    public function providerName(): string { return 'mock_whatsapp'; }

    private function scenario(string $key): string
    {
        if (str_contains($key, 'rate_limit'))    return 'rate_limit';
        if (str_contains($key, 'transient_fail')) return 'transient_fail';
        if (str_contains($key, 'perm_fail'))     return 'perm_fail';
        return 'success';
    }
}
