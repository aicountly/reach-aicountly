<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Reach → Console notification API client.
 *
 * Templates and SMS/email gateways live on Console. Reach sends editorial events here.
 *
 * Reach server-php/.env:
 *   CONSOLE_API_BASE_URL=https://console.aicountly.org/api
 *   CONSOLE_API_TOKEN=<same as Console CONSOLE_SERVICE_KEY>
 */
final class ConsoleNotificationClient
{
    private const PRODUCT = 'reach';

    /** @var array<string, array<string,mixed>|null> */
    private static array $resolveCache = [];

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && self::token() !== '';
    }

    public static function baseUrl(): string
    {
        foreach (['CONSOLE_API_BASE_URL', 'CONSOLE_API_URL'] as $key) {
            $url = rtrim(trim((string) (getenv($key) ?: $_ENV[$key] ?? '')), '/');
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private static function token(): string
    {
        return trim((string) (getenv('CONSOLE_API_TOKEN') ?: $_ENV['CONSOLE_API_TOKEN'] ?? ''));
    }

    /** @param array<string, scalar|null> $vars */
    public static function resolve(string $channel, string $triggerKey, array $vars = []): ?array
    {
        if (!self::isConfigured()) {
            return null;
        }

        $cacheKey = $channel . ':' . $triggerKey . ':' . md5(json_encode($vars, JSON_THROW_ON_ERROR));
        if (array_key_exists($cacheKey, self::$resolveCache)) {
            return self::$resolveCache[$cacheKey];
        }

        $query = http_build_query([
            'product'     => self::PRODUCT,
            'channel'     => $channel,
            'trigger_key' => $triggerKey,
        ]);

        $response = self::request('GET', '/portal/notifications/resolve?' . $query);
        if ($response === null || !($response['found'] ?? false)) {
            self::$resolveCache[$cacheKey] = null;

            return null;
        }

        $tpl = [
            'template_id'     => (int) ($response['template_id'] ?? 0),
            'trigger_key'     => $triggerKey,
            'channel'         => $channel,
            'status'          => (string) ($response['status'] ?? ''),
            'active'          => (bool) ($response['active'] ?? false),
            'body'            => (string) ($response['body'] ?? ''),
            'subject'         => $response['subject'] ?? null,
            'dlt_template_id' => (string) ($response['dlt_template_id'] ?? ''),
        ];

        self::$resolveCache[$cacheKey] = $tpl;

        return $tpl;
    }

    public static function isActive(string $channel, string $triggerKey): bool
    {
        $tpl = self::resolve($channel, $triggerKey);

        return $tpl !== null && ($tpl['active'] ?? false);
    }

    public static function clearCache(): void
    {
        self::$resolveCache = [];
    }

    /** @param array<string, scalar|null> $vars */
    public static function sendEmail(string $triggerKey, string $email, array $vars, string $subjectFallback, string $bodyFallback): bool
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $response = self::request('POST', '/portal/notifications/send', [
            'product'     => self::PRODUCT,
            'trigger_key' => $triggerKey,
            'channel'     => 'email',
            'recipient'   => ['email' => $email],
            'variables'   => $vars,
            'options'     => [
                'subject'       => $subjectFallback,
                'body_fallback' => $bodyFallback,
            ],
        ]);

        return (bool) ($response['sent'] ?? false);
    }

    /** @param array<string, scalar|null> $vars */
    public static function sendSms(string $triggerKey, string $email, array $vars, string $fallback): bool
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $response = self::request('POST', '/portal/notifications/send', [
            'product'     => self::PRODUCT,
            'trigger_key' => $triggerKey,
            'channel'     => 'sms',
            'recipient'   => ['email' => $email],
            'variables'   => $vars,
            'options'     => ['fallback' => $fallback],
        ]);

        return (bool) ($response['sent'] ?? false);
    }

    /** @return array<string,mixed>|null */
    private static function request(string $method, string $path, ?array $body = null): ?array
    {
        if (!self::isConfigured()) {
            return null;
        }

        if (!function_exists('curl_init')) {
            log_message('error', '[ConsoleNotificationClient] curl extension required.');
            return null;
        }

        $url = self::baseUrl() . $path;
        $ch  = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . self::token(),
            'X-Source: reach.aicountly.org',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_SLASHES);
            $headers[]                = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opts);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            log_message('error', sprintf(
                '[ConsoleNotificationClient] %s %s failed status=%d err=%s',
                $method,
                $path,
                $status,
                $err,
            ));

            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $data = $decoded['data'] ?? $decoded;

        return is_array($data) ? $data : null;
    }
}
