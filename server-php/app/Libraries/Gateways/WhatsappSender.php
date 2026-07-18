<?php

declare(strict_types=1);

namespace App\Libraries\Gateways;

/**
 * WhatsApp Business API via Infobip-style endpoint.
 *
 * Env: WHATSAPP_API_KEY, WHATSAPP_WABA_ID, WHATSAPP_SENDER_ID, WHATSAPP_API_URL, WHATSAPP_SKIP_SEND
 */
final class WhatsappSender
{
    private const DEFAULT_API_URL = 'https://api.infobip.com/whatsapp/1/message/text';

    public static function isConfigured(): bool
    {
        return trim((string) (getenv('WHATSAPP_API_KEY') ?: $_ENV['WHATSAPP_API_KEY'] ?? '')) !== ''
            && trim((string) (getenv('WHATSAPP_WABA_ID') ?: $_ENV['WHATSAPP_WABA_ID'] ?? '')) !== '';
    }

    public static function isSendDisabled(): bool
    {
        $raw = strtolower(trim((string) (getenv('WHATSAPP_SKIP_SEND') ?: $_ENV['WHATSAPP_SKIP_SEND'] ?? 'true')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public static function normalizePhone(string $phone): string
    {
        return SmsSender::normalizePhone($phone);
    }

    public static function send(string $recipient, string $content): bool
    {
        $recipient = self::normalizePhone($recipient);
        $content   = trim($content);

        if ($recipient === '' || $content === '') {
            return false;
        }

        if (!self::isConfigured()) {
            log_message('error', 'WhatsappSender::send skipped — WHATSAPP_API_KEY or WHATSAPP_WABA_ID not configured.');

            return false;
        }

        if (self::isSendDisabled()) {
            log_message('info', "WhatsappSender::send skipped (WHATSAPP_SKIP_SEND) — would send to {$recipient}");

            return true;
        }

        if (!function_exists('curl_init')) {
            log_message('error', 'WhatsappSender::send skipped — PHP curl extension is not enabled.');

            return false;
        }

        $apiUrl = trim((string) (getenv('WHATSAPP_API_URL') ?: $_ENV['WHATSAPP_API_URL'] ?? self::DEFAULT_API_URL));
        $from   = trim((string) (getenv('WHATSAPP_SENDER_ID') ?: $_ENV['WHATSAPP_SENDER_ID'] ?? ''));
        if ($from === '') {
            $from = trim((string) (getenv('WHATSAPP_WABA_ID') ?: $_ENV['WHATSAPP_WABA_ID'] ?? ''));
        }

        $payload = [
            'from'    => $from,
            'to'      => $recipient,
            'content' => ['text' => $content],
        ];

        $body = json_encode($payload);
        if ($body === false) {
            return false;
        }

        $ch = curl_init($apiUrl);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: App ' . trim((string) (getenv('WHATSAPP_API_KEY') ?: $_ENV['WHATSAPP_API_KEY'] ?? '')),
            ],
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr !== '') {
            log_message('error', "WhatsappSender::send curl error: {$curlErr}");

            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            log_message('error', "WhatsappSender::send HTTP {$httpCode}: {$response}");

            return false;
        }

        return true;
    }
}
