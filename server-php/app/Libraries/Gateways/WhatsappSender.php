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

    /**
     * Send an approved WhatsApp Business template (required for marketing
     * messages outside the 24-hour customer-service window).
     *
     * Infobip: POST {WHATSAPP_TEMPLATE_API_URL|https://api.infobip.com/whatsapp/1/message/template}
     *   {"messages":[{"from","to","messageId","content":{"templateName","templateData":{"body":{"placeholders":[...]}},"language"}}]}
     *
     * Built from Infobip's public API reference; not yet exercised against the
     * live AICOUNTLY account. Returns the provider message id on acceptance,
     * null otherwise (including when WHATSAPP_SKIP_SEND is on — callers must
     * check isSendDisabled() first and record an honest "skipped").
     *
     * @param list<string> $placeholders
     */
    public static function sendTemplate(string $recipient, string $templateName, array $placeholders = [], string $language = 'en', ?string $clientMessageId = null): ?string
    {
        self::$lastError = null;
        $recipient    = self::normalizePhone($recipient);
        $templateName = trim($templateName);
        if ($recipient === '' || $templateName === '') {
            self::$lastError = 'recipient and template name are required';
            return null;
        }
        if (!self::isConfigured()) {
            self::$lastError = 'WHATSAPP_API_KEY or WHATSAPP_WABA_ID not configured';
            return null;
        }
        if (self::isSendDisabled()) {
            self::$lastError = 'WHATSAPP_SKIP_SEND is on';
            return null;
        }
        if (!function_exists('curl_init')) {
            self::$lastError = 'PHP curl extension is not enabled';
            return null;
        }

        $base   = trim((string) (getenv('WHATSAPP_TEMPLATE_API_URL') ?: $_ENV['WHATSAPP_TEMPLATE_API_URL'] ?? ''));
        $apiUrl = $base !== '' ? $base : 'https://api.infobip.com/whatsapp/1/message/template';
        $from   = trim((string) (getenv('WHATSAPP_SENDER_ID') ?: $_ENV['WHATSAPP_SENDER_ID'] ?? ''));
        if ($from === '') {
            $from = trim((string) (getenv('WHATSAPP_WABA_ID') ?: $_ENV['WHATSAPP_WABA_ID'] ?? ''));
        }

        $message = [
            'from'    => $from,
            'to'      => $recipient,
            'content' => [
                'templateName' => $templateName,
                'templateData' => ['body' => ['placeholders' => array_values(array_map('strval', $placeholders))]],
                'language'     => $language !== '' ? $language : 'en',
            ],
        ];
        if ($clientMessageId !== null && $clientMessageId !== '') {
            $message['messageId'] = substr($clientMessageId, 0, 100);
        }
        $body = json_encode(['messages' => [$message]]);
        if ($body === false) {
            self::$lastError = 'could not encode payload';
            return null;
        }

        $ch = curl_init($apiUrl);
        if ($ch === false) {
            self::$lastError = 'curl init failed';
            return null;
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
            self::$lastError = 'curl: ' . $curlErr;
            log_message('error', 'WhatsappSender::sendTemplate ' . self::$lastError);
            return null;
        }
        self::$lastHttpCode = $httpCode;
        if ($httpCode < 200 || $httpCode >= 300) {
            self::$lastError = "HTTP {$httpCode}: " . substr((string) $response, 0, 300);
            log_message('error', 'WhatsappSender::sendTemplate ' . self::$lastError);
            return null;
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $first   = is_array($decoded) ? ($decoded['messages'][0] ?? null) : null;
        $group   = is_array($first) ? strtoupper((string) ($first['status']['groupName'] ?? '')) : '';
        if ($group === 'REJECTED' || $group === 'UNDELIVERABLE') {
            self::$lastError = 'provider rejected: ' . (string) ($first['status']['description'] ?? $group);
            return null;
        }
        $id = is_array($first) ? trim((string) ($first['messageId'] ?? '')) : '';
        if ($id === '') {
            self::$lastError = 'provider response had no messageId';
            return null;
        }

        return $id;
    }

    private static ?string $lastError = null;
    private static int $lastHttpCode = 0;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    public static function getLastHttpCode(): int
    {
        return self::$lastHttpCode;
    }
}
