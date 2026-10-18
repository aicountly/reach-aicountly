<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Gateways\Mailer;

/**
 * Campaign email through Mailer → EmailDispatcher (Console's email providers for reach, primary
 * then fallback; .env EMAIL_API_KEY as the last resort).
 *
 * The unsubscribe link and postal-address footer are already in the HTML/text body
 * (ChannelDispatchService::buildMessage). When the message carries metadata.unsubscribe_url it
 * is also sent as RFC 8058 List-Unsubscribe / List-Unsubscribe-Post headers (the SMTP provider
 * sets them; the HTTP API carries the body only). A send is accepted only when a provider
 * accepted it; otherwise the exception lists every provider's reason.
 */
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

        $opts         = [];
        $unsubscribe  = trim((string) ($message->metadata['unsubscribe_url'] ?? ''));
        if ($unsubscribe !== '' && preg_match('#^https?://#i', $unsubscribe) === 1) {
            $opts['headers'] = [
                'List-Unsubscribe'      => '<' . $unsubscribe . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ];
        }

        if (!Mailer::send($to, $subject, $html, $plain, $opts)) {
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
            rawResponse:       json_encode(['provider' => Mailer::getLastProvider(), 'mode' => Mailer::getLastSendMode()]) ?: null,
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
            'features'   => ['tracking', 'unsubscribe_header', 'failover'],
            'provider'   => 'console_email',
            'providers'  => array_column(Mailer::providerSummary()['providers'], 'code'),
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
        return 'console_email';
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
