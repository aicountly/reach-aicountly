<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Libraries\Intelligence\Connectors\DTOs\MetricBatch;

/**
 * Live Google Analytics 4 (Data API) content-analytics connector.
 *
 * Previously ConnectorProviderFactory::contentAnalytics() always returned
 * MockContentAnalyticsConnector regardless of configuration — a configured
 * GA4 property never actually reached this pipeline; dashboards silently
 * consumed mock rows. This connector performs the real GA4 Data API
 * `runReport` call via the shared GoogleServiceAccountToken JWT exchange
 * (same pattern as GoogleSearchConsoleConnector) and never fabricates a
 * result: disabled/misconfigured/error states are reported honestly.
 */
class GoogleAnalyticsContentConnector implements ContentAnalyticsConnectorInterface
{
    public const STATUS_DISABLED      = 'disabled';
    public const STATUS_MISCONFIGURED = 'misconfigured';
    public const STATUS_ERROR         = 'error';

    private const SCOPE    = 'https://www.googleapis.com/auth/analytics.readonly';
    private const API_BASE = 'https://analyticsdata.googleapis.com/v1beta';

    private bool $enabled;
    private string $keyPath;
    private string $propertyId;
    private int $timeout;
    private int $lastHttpStatus = 0;

    public function __construct(
        ?bool $enabled = null,
        ?string $keyPath = null,
        ?string $propertyId = null,
        ?int $timeout = null,
    ) {
        $this->enabled    = $enabled ?? self::envBool('CONTENT_ANALYTICS_ENABLED');
        $this->keyPath    = trim($keyPath ?? self::envString('CONTENT_ANALYTICS_SERVICE_ACCOUNT_KEY_PATH'));
        $this->propertyId = trim($propertyId ?? self::envString('CONTENT_ANALYTICS_PROPERTY_ID'));
        $this->timeout    = $timeout ?? (int) (self::envString('CONTENT_ANALYTICS_TIMEOUT_SECONDS') ?: '30');
    }

    public function providerName(): string
    {
        return 'google_analytics_4';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @return array{status:string, detail:string, property_id:string, client_email:?string} */
    public function configurationState(): array
    {
        if (! $this->enabled) {
            return ['status' => self::STATUS_DISABLED, 'detail' => 'CONTENT_ANALYTICS_ENABLED is false.', 'property_id' => '', 'client_email' => null];
        }

        $inspection = GoogleServiceAccountToken::inspect($this->keyPath);

        if (! $inspection['path_configured']) {
            $detail = 'CONTENT_ANALYTICS_SERVICE_ACCOUNT_KEY_PATH is not set.';
        } elseif (! $inspection['file_exists']) {
            $detail = 'Service-account key file does not exist at the configured path.';
        } elseif (! $inspection['file_readable']) {
            $detail = 'Service-account key file is not readable by the web/CLI user.';
        } elseif (! $inspection['parsable']) {
            $detail = 'Service-account key file is missing private_key or client_email.';
        } elseif ($this->propertyId === '') {
            $detail = 'CONTENT_ANALYTICS_PROPERTY_ID is not set.';
        } else {
            return [
                'status' => 'configured', 'detail' => 'Credentials present; run a health check to confirm property access.',
                'property_id' => $this->propertyId, 'client_email' => $inspection['client_email'],
            ];
        }

        return ['status' => self::STATUS_MISCONFIGURED, 'detail' => $detail, 'property_id' => $this->propertyId, 'client_email' => $inspection['client_email']];
    }

    public function healthCheck(): ConnectorHealthResult
    {
        if (! $this->enabled) {
            return ConnectorHealthResult::failing('GA4 connector is disabled.', self::STATUS_DISABLED);
        }

        $state = $this->configurationState();
        if ($state['status'] !== 'configured') {
            return ConnectorHealthResult::failing($state['detail'], self::STATUS_MISCONFIGURED);
        }

        $start = hrtime(true);
        $token = GoogleServiceAccountToken::get($this->keyPath, self::SCOPE, min(10, $this->timeout));
        if ($token === null) {
            return ConnectorHealthResult::failing('Google rejected the service-account credentials.', 'AuthFailure', 401);
        }

        try {
            $this->runReport($token, [
                'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
                'metrics'    => [['name' => 'sessions']],
                'limit'      => '1',
            ]);
        } catch (\RuntimeException $e) {
            return ConnectorHealthResult::failing($e->getMessage(), self::STATUS_ERROR, $this->lastHttpStatus);
        }

        return ConnectorHealthResult::healthy((int) ((hrtime(true) - $start) / 1_000_000));
    }

    public function getCapabilities(): array
    {
        return [
            'backfill_days'         => 90,
            'batch_size'            => 100000,
            'supported_dimensions'  => ['pagePath', 'sessionSource', 'sessionMedium'],
            'provider_name'         => $this->providerName(),
        ];
    }

    public function fetchContentMetrics(IngestionRequest $request): MetricBatch
    {
        if (! $this->enabled) {
            return $this->emptyWith('GA4 connector is disabled.');
        }

        $state = $this->configurationState();
        if ($state['status'] !== 'configured') {
            return $this->emptyWith($state['detail']);
        }

        $token = GoogleServiceAccountToken::get($this->keyPath, self::SCOPE, min(10, $this->timeout));
        if ($token === null) {
            throw new \RuntimeException('google_analytics_4: service-account authentication failed.');
        }

        $limit  = max(1, min($request->batchSize, 100000));
        $offset = (int) ($request->cursorState['offset'] ?? 0);

        $response = $this->runReport($token, [
            'dateRanges' => [['startDate' => $request->dateFrom, 'endDate' => $request->dateTo]],
            'dimensions' => [['name' => 'date'], ['name' => 'pagePath'], ['name' => 'sessionSource'], ['name' => 'sessionMedium']],
            'metrics'    => [
                ['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'newUsers'],
                ['name' => 'engagedSessions'], ['name' => 'engagementRate'],
                ['name' => 'averageSessionDuration'], ['name' => 'screenPageViews'],
            ],
            'limit'  => (string) $limit,
            'offset' => (string) $offset,
        ], $token);

        $rawRows = is_array($response['rows'] ?? null) ? $response['rows'] : [];
        $rows    = [];
        foreach ($rawRows as $row) {
            $rows[] = $this->normaliseRow($row);
        }

        $returned   = count($rows);
        $isComplete = $returned < $limit;
        $nextCursor = $isComplete ? null : ['offset' => $offset + $returned];

        return new MetricBatch(
            rows: $rows,
            nextCursorState: $nextCursor,
            providerFreshnessAt: date('Y-m-d H:i:s'),
            rowCount: $returned,
            isComplete: $isComplete,
            warningMessage: $returned === 0 && $offset === 0
                ? 'GA4 returned no rows for ' . $request->dateFrom . '..' . $request->dateTo . '.'
                : null,
        );
    }

    public function resolvePageToIdentity(string $pagePath): ?string
    {
        // GA4 identifies pages by path only; canonical-URL resolution happens
        // one layer up (Intelligence identity mapping), never fabricated here.
        return $pagePath !== '' ? $pagePath : null;
    }

    public function listAvailableProperties(): array
    {
        return $this->propertyId !== '' ? ["properties/{$this->propertyId}"] : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function runReport(string $token, array $body): array
    {
        if ($this->propertyId === '') {
            throw new \RuntimeException('google_analytics_4: GA4_PROPERTY_ID is not set.');
        }

        $url = self::API_BASE . '/properties/' . rawurlencode($this->propertyId) . ':runReport';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->lastHttpStatus = $status;

        if ($errno !== CURLE_OK) {
            throw new \RuntimeException('google_analytics_4: transport error: ' . $this->redact($errmsg));
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('google_analytics_4: malformed JSON response (HTTP ' . $status . ').');
        }

        if ($status === 429) {
            throw new \RuntimeException('google_analytics_4: quota exceeded (HTTP 429).');
        }

        if ($status >= 400) {
            $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new \RuntimeException('google_analytics_4: ' . $this->redact((string) $message));
        }

        return $decoded;
    }

    /** @param array<string, mixed> $row */
    private function normaliseRow(array $row): array
    {
        $dims = array_map(static fn ($d) => $d['value'] ?? null, $row['dimensionValues'] ?? []);
        $mets = array_map(static fn ($m) => $m['value'] ?? null, $row['metricValues'] ?? []);

        return [
            'metric_date'              => $dims[0] ?? null,
            'page_path'                => $dims[1] ?? null,
            'source'                   => $dims[2] ?? null,
            'medium'                   => $dims[3] ?? null,
            'sessions'                 => (int) ($mets[0] ?? 0),
            'users'                    => (int) ($mets[1] ?? 0),
            'new_users'                => (int) ($mets[2] ?? 0),
            'engaged_sessions'         => (int) ($mets[3] ?? 0),
            'engagement_rate'          => round((float) ($mets[4] ?? 0), 6),
            'avg_engagement_time_secs' => round((float) ($mets[5] ?? 0), 2),
            'page_views'               => (int) ($mets[6] ?? 0),
        ];
    }

    private function emptyWith(string $warning): MetricBatch
    {
        return new MetricBatch(rows: [], nextCursorState: null, providerFreshnessAt: date('Y-m-d H:i:s'), rowCount: 0, isComplete: true, warningMessage: $warning);
    }

    private function redact(string $message): string
    {
        return preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $message) ?? $message;
    }

    private static function envString(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? '';
        }

        return is_string($value) ? $value : '';
    }

    private static function envBool(string $key): bool
    {
        return filter_var(self::envString($key), FILTER_VALIDATE_BOOL);
    }
}
