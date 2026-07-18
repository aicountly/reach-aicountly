<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Gateways\Mailer;

class GatewayEmailSender implements EmailSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt
    {
        $to = $this->resolveRecipient($message);
        $subject = (string) ($message->metadata['subject'] ?? 'Campaign');
        $html = $message->content;

        if (!Mailer::send($to, $subject, $html)) {
            throw new \RuntimeException(
                'Email gateway rejected send: ' . (Mailer::getLastError() ?? 'unknown'),
                502,
            );
        }

        $messageId = Mailer::getLastMessageId()
            ?? 'email-' . substr(md5($message->idempotencyKey), 0, 12);

        return new ProviderReceipt(
            providerMessageId: $messageId,
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
        return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'sent');
    }

    public function getCapabilities(): array
    {
        return [
            'batch_size' => 1000,
            'rate_limit' => 100,
            'features'   => ['tracking', 'unsubscribe_header'],
            'provider'   => 'itwalk',
        ];
    }

    public function isEnabled(): bool
    {
        return Mailer::isConfigured();
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return true;
    }

    public function providerName(): string
    {
        return 'itwalk_email';
    }

    private function resolveRecipient(ChannelMessage $message): string
    {
        $to = trim($message->recipientAddress);
        if ($to !== '' && $to !== 'list@broadcast' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $to;
        }

        $metaTo = trim((string) ($message->metadata['to'] ?? $message->metadata['recipient_email'] ?? ''));
        if ($metaTo !== '' && filter_var($metaTo, FILTER_VALIDATE_EMAIL)) {
            return $metaTo;
        }

        $fallback = trim((string) (getenv('CAMPAIGN_DISPATCH_TEST_EMAIL') ?: $_ENV['CAMPAIGN_DISPATCH_TEST_EMAIL'] ?? ''));
        if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
            return $fallback;
        }

        throw new \RuntimeException(
            'No valid email recipient. Set CAMPAIGN_DISPATCH_TEST_EMAIL in .env or configure campaign audience.',
            422,
        );
    }
}
