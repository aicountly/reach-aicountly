<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Gateways\SmsSender;

class AocPortalSmsSender implements SmsSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt
    {
        $phone = $this->resolveRecipient($message);
        $templateId = trim((string) ($message->metadata['dlt_template_id'] ?? $message->templateId ?? ''));

        if (!SmsSender::send($phone, $message->content, $templateId !== '' ? $templateId : null)) {
            throw new \RuntimeException(
                'SMS gateway rejected send. Check SMS_API_KEY, DLT template ID, and SMS_SKIP_SEND.',
                502,
            );
        }

        return new ProviderReceipt(
            providerMessageId: 'sms-' . substr(md5($message->idempotencyKey . $phone), 0, 12),
            status:            'accepted',
            acceptedAt:        new \DateTimeImmutable(),
        );
    }

    public function getStatus(string $providerMessageId): ProviderStatus
    {
        return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'delivered');
    }

    public function getCapabilities(): array
    {
        return [
            'dlt_required'   => true,
            'char_limit'     => 160,
            'max_body_chars' => 160,
            'encoding'       => 'GSM7',
            'provider_name'  => 'aoc_portal',
        ];
    }

    public function isEnabled(): bool
    {
        return SmsSender::isConfigured();
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return true;
    }

    public function providerName(): string
    {
        return 'aoc_portal_sms';
    }

    private function resolveRecipient(ChannelMessage $message): string
    {
        $phone = SmsSender::normalizePhone($message->recipientAddress);
        if ($phone !== '' && $message->recipientAddress !== 'broadcast') {
            return $phone;
        }

        $metaPhone = trim((string) ($message->metadata['to'] ?? $message->metadata['recipient_phone'] ?? ''));
        $phone = SmsSender::normalizePhone($metaPhone);
        if ($phone !== '') {
            return $phone;
        }

        $fallback = trim((string) (getenv('CAMPAIGN_DISPATCH_TEST_PHONE') ?: $_ENV['CAMPAIGN_DISPATCH_TEST_PHONE'] ?? ''));
        $phone = SmsSender::normalizePhone($fallback);
        if ($phone !== '') {
            return $phone;
        }

        throw new \RuntimeException(
            'No valid SMS recipient. Set CAMPAIGN_DISPATCH_TEST_PHONE in .env or configure campaign audience.',
            422,
        );
    }
}
