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

        $plain   = isset($message->metadata['body_text']) && is_string($message->metadata['body_text']) && $message->metadata['body_text'] !== ''
            ? $message->metadata['body_text']
            : null;

        if (!Mailer::send($to, $subject, $html, $plain)) {
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

    /**
     * The recipient is exactly the address on the message. There is no
     * broadcast placeholder and no fallback to a test inbox: audience
     * resolution supplies real addresses, and the test-send endpoint passes
     * the test address explicitly.
     */
    private function resolveRecipient(ChannelMessage $message): string
    {
        $to = trim($message->recipientAddress);
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $to;
        }

        throw new \RuntimeException('Invalid email recipient address.', 422);
    }
}
