<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Gateways\WhatsappSender;

class GatewayWhatsAppSender implements WhatsAppSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt
    {
        $phone = $this->resolveRecipient($message);
        $content = trim($message->content);
        if ($content === '' && !empty($message->metadata['template_id'])) {
            $content = (string) ($message->metadata['template_id'] ?? 'campaign');
        }

        if (!WhatsappSender::send($phone, $content)) {
            throw new \RuntimeException(
                'WhatsApp gateway rejected send. Check WHATSAPP_API_KEY, WHATSAPP_WABA_ID, and WHATSAPP_SKIP_SEND.',
                502,
            );
        }

        return new ProviderReceipt(
            providerMessageId: 'wa-' . substr(md5($message->idempotencyKey . $phone), 0, 12),
            status:            'accepted',
            acceptedAt:        new \DateTimeImmutable(),
        );
    }

    public function getTemplates(): array
    {
        return [];
    }

    public function getTemplateStatus(string $templateId): array
    {
        return ['template_id' => $templateId, 'status' => 'unknown'];
    }

    public function getStatus(string $providerMessageId): ProviderStatus
    {
        return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'delivered');
    }

    public function getCapabilities(): array
    {
        return [
            'template_required' => false,
            'messaging_window'  => true,
            'media_types'       => ['text'],
            'provider_name'     => 'infobip_whatsapp',
        ];
    }

    public function isEnabled(): bool
    {
        return WhatsappSender::isConfigured();
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return true;
    }

    public function providerName(): string
    {
        return 'infobip_whatsapp';
    }

    private function resolveRecipient(ChannelMessage $message): string
    {
        $phone = WhatsappSender::normalizePhone($message->recipientAddress);
        if ($phone !== '' && $message->recipientAddress !== 'broadcast') {
            return $phone;
        }

        $metaPhone = trim((string) ($message->metadata['to'] ?? $message->metadata['recipient_phone'] ?? ''));
        $phone = WhatsappSender::normalizePhone($metaPhone);
        if ($phone !== '') {
            return $phone;
        }

        $fallback = trim((string) (getenv('CAMPAIGN_DISPATCH_TEST_PHONE') ?: $_ENV['CAMPAIGN_DISPATCH_TEST_PHONE'] ?? ''));
        $phone = WhatsappSender::normalizePhone($fallback);
        if ($phone !== '') {
            return $phone;
        }

        throw new \RuntimeException(
            'No valid WhatsApp recipient. Set CAMPAIGN_DISPATCH_TEST_PHONE in .env or configure campaign audience.',
            422,
        );
    }
}
