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
     * @param array{timeout?: int} $options
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse;
}
