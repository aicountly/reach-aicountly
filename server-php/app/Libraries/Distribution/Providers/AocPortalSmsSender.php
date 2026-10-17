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
        if ($templateId === '') {
            throw new \RuntimeException('DLT template ID is required for every SMS (TRAI).', 422);
        }

        // SmsSender::send() returns true when SMS_SKIP_SEND is on without
        // contacting the gateway. That must never be recorded as accepted.
        if (SmsSender::isSendDisabled()) {
            throw new SendSkippedException('SMS_SKIP_SEND is on — SMS not sent. Set SMS_SKIP_SEND=false to send for real.');
        }

        if (!SmsSender::send($phone, $message->content, $templateId)) {
            throw new \RuntimeException(
                'SMS gateway rejected send. Check SMS_API_KEY, DLT template ID, and SMS_SKIP_SEND.',
                502,
            );
        }

        return new ProviderReceipt(
            providerMessageId: 'local-sms-' . substr(md5($message->idempotencyKey . $phone), 0, 12),
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
        return SmsSender::isConfigured() && !SmsSender::isSendDisabled();
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return true;
    }

    public function providerName(): string
    {
        return 'aoc_portal_sms';
    }

    /** Exactly the number on the message — no broadcast placeholder, no test-number fallback. */
    private function resolveRecipient(ChannelMessage $message): string
    {
        $phone = SmsSender::normalizePhone($message->recipientAddress);
        if (strlen($phone) >= 10) {
            return $phone;
        }

        throw new \RuntimeException('Invalid SMS recipient number.', 422);
    }
}
