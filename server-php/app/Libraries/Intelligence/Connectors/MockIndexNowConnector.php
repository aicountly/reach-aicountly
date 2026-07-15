<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;

class MockIndexNowConnector implements IndexNowConnectorInterface
{
    private bool  $enabled;
    private bool  $shouldFail;
    private bool  $shouldRateLimit;
    private array $submittedUrls = [];
    private array $callLog = [];

    private const ALLOWED_ENDPOINTS = [
        'https://api.indexnow.org',
        'https://www.bing.com',
        'https://search.google.com',
    ];

    public function __construct(bool $enabled = true, bool $shouldFail = false, bool $shouldRateLimit = false)
    {
        $this->enabled         = $enabled;
        $this->shouldFail      = $shouldFail;
        $this->shouldRateLimit = $shouldRateLimit;
    }

    public function providerName(): string { return 'mock_indexnow'; }
    public function isEnabled(): bool      { return $this->enabled; }

    public function healthCheck(): ConnectorHealthResult
    {
        if ($this->shouldFail) {
            return ConnectorHealthResult::failing('mock indexnow failure', 'Transient');
        }
        return ConnectorHealthResult::healthy(20);
    }

    public function getCapabilities(): array
    {
        return [
            'max_batch_size' => 10000,
            'provider_name'  => 'mock_indexnow',
            'allowed_endpoints' => self::ALLOWED_ENDPOINTS,
        ];
    }

    public function submitUrl(string $url, string $key): array
    {
        $this->callLog[] = ['method' => 'submitUrl', 'url' => $url];

        if ($this->shouldRateLimit) {
            return ['success' => false, 'http_status' => 429, 'error' => 'rate_limited', 'retry_after_secs' => 60];
        }
        if ($this->shouldFail) {
            return ['success' => false, 'http_status' => 500, 'error' => 'server_error'];
        }

        $this->submittedUrls[] = $url;
        return ['success' => true, 'http_status' => 200];
    }

    public function submitBatch(array $urls, string $key): array
    {
        $this->callLog[] = ['method' => 'submitBatch', 'count' => count($urls)];

        if ($this->shouldRateLimit) {
            return ['success' => false, 'http_status' => 429, 'error' => 'rate_limited', 'retry_after_secs' => 60];
        }
        if ($this->shouldFail) {
            return ['success' => false, 'http_status' => 500, 'error' => 'server_error'];
        }

        foreach ($urls as $url) {
            $this->submittedUrls[] = $url;
        }
        return ['success' => true, 'http_status' => 200, 'accepted' => count($urls)];
    }

    public function validateKeyLocation(string $keyUrl): bool
    {
        return str_ends_with($keyUrl, '.txt');
    }

    public function getSubmittedUrls(): array { return $this->submittedUrls; }
    public function getCallLog(): array        { return $this->callLog; }
    public function clearState(): void
    {
        $this->submittedUrls = [];
        $this->callLog       = [];
    }
}
