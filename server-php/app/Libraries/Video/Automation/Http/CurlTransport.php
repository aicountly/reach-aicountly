<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Http;

/**
 * cURL implementation of HttpTransport. Never follows redirects (a provider
 * redirect is surfaced to the caller), verifies TLS, and reports a transport
 * failure as status 0 with the cURL error rather than throwing.
 */
final class CurlTransport implements HttpTransport
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return new HttpResponse(0, '', [], 'curl_init failed');
        }

        $responseHeaders = [];
        $headerLines     = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // cURL adds "Expect: 100-continue" for large bodies; Google's upload
        // endpoint does not need it and it costs a round trip.
        $headerLines[] = 'Expect:';

        $timeout = (int) ($options['timeout'] ?? 60);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if (strtoupper($method) === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return new HttpResponse(0, '', $responseHeaders, $error !== '' ? $error : 'request failed');
        }

        return new HttpResponse($status, (string) $raw, $responseHeaders);
    }
}
