<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Http;

/**
 * cURL implementation of HttpTransport. Never follows redirects (a provider
 * redirect is surfaced to the caller), verifies TLS, and reports a transport
 * failure as status 0 with the cURL error rather than throwing.
 *
 * Large files never pass through PHP memory:
 *   options['sink_file']  stream the response body into this file path
 *                         (the returned body is then '')
 *   options['body_file']  stream the request body from this file path
 *                         (with $body = null; e.g. a PUT to a presigned URL)
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

        $in   = null;
        $sink = null;
        if (isset($options['body_file']) && $body === null) {
            $in = @fopen((string) $options['body_file'], 'rb');
            if ($in === false) {
                curl_close($ch);
                return new HttpResponse(0, '', [], 'cannot read ' . basename((string) $options['body_file']));
            }
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD     => true,
                CURLOPT_INFILE     => $in,
                CURLOPT_INFILESIZE => (int) filesize((string) $options['body_file']),
            ]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        }
        if (isset($options['sink_file'])) {
            $sink = @fopen((string) $options['sink_file'], 'wb');
            if ($sink === false) {
                curl_close($ch);
                if (is_resource($in)) {
                    fclose($in);
                }
                return new HttpResponse(0, '', [], 'cannot write the download file');
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $sink);
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($sink)) {
            fclose($sink);
        }

        if ($raw === false) {
            return new HttpResponse(0, '', $responseHeaders, $error !== '' ? $error : 'request failed');
        }

        return new HttpResponse($status, $sink !== null ? '' : (string) $raw, $responseHeaders);
    }
}
