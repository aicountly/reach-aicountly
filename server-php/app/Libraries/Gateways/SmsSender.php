<?php

declare(strict_types=1);

namespace App\Libraries\Gateways;

/**
 * Transactional SMS via Digimiles / AOC Portal or SMS Gateway Hub.
 *
 * Env: SMS_PROVIDER, SMS_API_URL, SMS_API_KEY, SMS_SENDER_ID, SMS_TYPE,
 *      SMS_COUNTRY_CODE, SMS_SKIP_SEND
 */
final class SmsSender
{
    private const DEFAULT_DIGIMILES_URL = 'https://api.aoc-portal.com/v1/sms';
    private const DEFAULT_GATEWAY_URL   = 'https://www.smsgatewayhub.com/api/mt/SendSMS';

    public static function isConfigured(): bool
    {
        return trim((string) (getenv('SMS_API_KEY') ?: $_ENV['SMS_API_KEY'] ?? '')) !== ''
            && trim((string) (getenv('SMS_SENDER_ID') ?: $_ENV['SMS_SENDER_ID'] ?? '')) !== '';
    }

    public static function isSendDisabled(): bool
    {
        $raw = strtolower(trim((string) (getenv('SMS_SKIP_SEND') ?: $_ENV['SMS_SKIP_SEND'] ?? 'true')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public static function provider(): string
    {
        $raw = strtolower(trim((string) (getenv('SMS_PROVIDER') ?: $_ENV['SMS_PROVIDER'] ?? 'digimiles')));

        if (in_array($raw, ['digimiles', 'aoc-portal', 'aoc'], true)) {
            return 'digimiles';
        }

        return 'smsgatewayhub';
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return '';
        }

        $cc = preg_replace('/\D+/', '', trim((string) (getenv('SMS_COUNTRY_CODE') ?: $_ENV['SMS_COUNTRY_CODE'] ?? '91'))) ?? '91';
        if ($cc !== '' && strlen($digits) === 10) {
            return $cc . $digits;
        }

        return $digits;
    }

    public static function send(string $recipient, string $content, ?string $templateId = null): bool
    {
        $recipient = self::normalizePhone($recipient);
        $content   = trim($content);

        if ($recipient === '' || $content === '') {
            return false;
        }

        if (!self::isConfigured()) {
            log_message('error', 'SmsSender::send skipped — SMS_API_KEY or SMS_SENDER_ID not configured.');

            return false;
        }

        if (self::isSendDisabled()) {
            log_message('info', 'SmsSender::send skipped (SMS_SKIP_SEND) — would send via '
                . self::provider() . " to {$recipient}");

            return true;
        }

        return self::provider() === 'digimiles'
            ? self::sendViaDigimiles($recipient, $content, $templateId)
            : self::sendViaSmsGatewayHub($recipient, $content, $templateId);
    }

    /** @param list<string> $recipients */
    public static function sendMany(array $recipients, string $content, ?string $templateId = null): void
    {
        $seen = [];
        foreach ($recipients as $phone) {
            $normalized = self::normalizePhone((string) $phone);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            self::send($normalized, $content, $templateId);
        }
    }

    private static function sendViaSmsGatewayHub(string $recipient, string $content, ?string $templateId): bool
    {
        if (!function_exists('curl_init')) {
            log_message('error', 'SmsSender::send skipped — PHP curl extension is not enabled.');

            return false;
        }

        $baseUrl = trim((string) (getenv('SMS_API_URL') ?: $_ENV['SMS_API_URL'] ?? '')) ?: self::DEFAULT_GATEWAY_URL;

        $query = [
            'APIKey'    => trim((string) (getenv('SMS_API_KEY') ?: $_ENV['SMS_API_KEY'] ?? '')),
            'senderid'  => trim((string) (getenv('SMS_SENDER_ID') ?: $_ENV['SMS_SENDER_ID'] ?? '')),
            'channel'   => trim((string) (getenv('SMS_GATEWAY_CHANNEL') ?: $_ENV['SMS_GATEWAY_CHANNEL'] ?? '2')),
            'DCS'       => trim((string) (getenv('SMS_GATEWAY_DCS') ?: $_ENV['SMS_GATEWAY_DCS'] ?? '0')),
            'flashsms'  => trim((string) (getenv('SMS_GATEWAY_FLASH') ?: $_ENV['SMS_GATEWAY_FLASH'] ?? '0')),
            'number'    => $recipient,
            'text'      => $content,
            'route'     => trim((string) (getenv('SMS_GATEWAY_ROUTE') ?: $_ENV['SMS_GATEWAY_ROUTE'] ?? 'clickhere')),
        ];

        $dltId = trim((string) ($templateId ?? ''));
        if ($dltId === '') {
            log_message('error', 'SmsSender::send skipped — DLT template ID is required.');

            return false;
        }
        $query['DLTTemplateId'] = $dltId;

        $url = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain, */*'],
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr !== '') {
            log_message('error', "SmsSender::send SMS Gateway Hub curl error: {$curlErr}");

            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            log_message('error', "SmsSender::send SMS Gateway Hub HTTP {$httpCode}: {$response}");

            return false;
        }

        return true;
    }

    private static function sendViaDigimiles(string $recipient, string $content, ?string $templateId): bool
    {
        if (!function_exists('curl_init')) {
            log_message('error', 'SmsSender::send skipped — PHP curl extension is not enabled.');

            return false;
        }

        $url = trim((string) (getenv('SMS_API_URL') ?: $_ENV['SMS_API_URL'] ?? '')) ?: self::DEFAULT_DIGIMILES_URL;

        $payload = [
            'sender' => trim((string) (getenv('SMS_SENDER_ID') ?: $_ENV['SMS_SENDER_ID'] ?? '')),
            'to'     => $recipient,
            'text'   => $content,
            'type'   => trim((string) (getenv('SMS_TYPE') ?: $_ENV['SMS_TYPE'] ?? '')) ?: 'TRANS',
        ];

        $templateId = trim((string) ($templateId ?? ''));
        if ($templateId === '') {
            log_message('error', 'SmsSender::send skipped — DLT template ID is required.');

            return false;
        }
        $payload['templateId'] = $templateId;

        $body = json_encode($payload);
        if ($body === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'apikey: ' . trim((string) (getenv('SMS_API_KEY') ?: $_ENV['SMS_API_KEY'] ?? '')),
            ],
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr !== '') {
            log_message('error', "SmsSender::send Digimiles curl error: {$curlErr}");

            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            log_message('error', "SmsSender::send Digimiles HTTP {$httpCode}: {$response}");

            return false;
        }

        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && !empty($decoded['error'])) {
                $message = trim((string) ($decoded['message'] ?? $response));
                log_message('error', "SmsSender::send Digimiles API error: {$message}");

                return false;
            }
        }

        return true;
    }
}
