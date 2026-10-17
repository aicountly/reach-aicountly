<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

/**
 * The one place the visibility assistants touch the network. Clients take an
 * injectable callable with this signature so tests never make a real call.
 */
final class AssistantHttp
{
    /**
     * @param list<string>          $headers
     * @param array<string, mixed>  $body
     * @return array{status: int, body: string}
     */
    public static function post(string $url, array $headers, array $body, int $timeout = 90): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'body' => json_encode(['error' => ['message' => 'Network error: ' . $error]]) ?: ''];
        }

        return ['status' => $status, 'body' => (string) $raw];
    }

    /** Provider error text with anything key-shaped removed. */
    public static function providerMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        $msg     = '';
        if (is_array($decoded)) {
            $err = $decoded['error'] ?? null;
            $msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err)) : (string) ($err ?? '');
        }
        if ($msg === '') {
            $msg = mb_substr(trim(strip_tags($body)), 0, 200);
        }
        $msg = preg_replace('/key=[^&\s"]+/i', 'key=[REDACTED]', $msg) ?? '';
        $msg = preg_replace('/\b(sk|pplx|AIza)[A-Za-z0-9_\-]{8,}/', '[REDACTED]', $msg) ?? '';

        return mb_substr($msg, 0, 300);
    }

    public static function env(string $name, string $default = ''): string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (($v === false || $v === null) && function_exists('env')) {
            $v = env($name);
        }

        return is_string($v) && trim($v) !== '' ? trim($v) : $default;
    }
}
