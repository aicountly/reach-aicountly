<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Http;

/**
 * The only way the video pipeline talks to the outside world, so every
 * provider client can be exercised in tests with a fake transport.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param array{timeout?: int, sink_file?: string, body_file?: string} $options
     *        sink_file: write the response body to this path (body returned as '');
     *        body_file: send this file as the request body (with $body = null)
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse;
}
