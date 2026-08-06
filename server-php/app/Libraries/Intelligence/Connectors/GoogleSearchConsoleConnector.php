<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

use App\Libraries\Intelligence\Connectors\DTOs\ConnectorHealthResult;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Libraries\Intelligence\Connectors\DTOs\MetricBatch;

/**
 * Live Google Search Console connector (Search Analytics API v3).
 *
 * Authenticates with a service-account key that has been granted access to the
 * Search Console property. Uses native cURL; no Google SDK dependency.
 *
 * Honest status reporting — this connector never invents data:
 * - STATUS_DISABLED      : SEARCH_CONSOLE_ENABLED is false
 * - STATUS_MISCONFIGURED : enabled but credentials or site property missing/unreadable
 * - STATUS_ERROR         : credentials present but Google rejected them or the API failed
 * - STATUS_NO_DATA       : authenticated successfully but the property returned zero rows
 * - healthy              : authenticated and the property is reachable
 *
 * Search Console finalises data on a 2-3 day lag. fetchSearchMetrics() clamps the
 * requested end date to `now - SEARCH_CONSOLE_DATA_LAG_DAYS` so partial days are
 * never ingested as if they were complete.
 */
class GoogleSearchConsoleConnector implements SearchConsoleConnectorInterface
{
    public const STATUS_DISABLED      = 'disabled';
    public const STATUS_MISCONFIGURED = 'misconfigured';
    public const STATUS_ERROR         = 'error';
    public const STATUS_NO_DATA       = 'no_data';

    /** Sentinel for rows Search Console returned without a country dimension. */
    public const COUNTRY_UNKNOWN = 'ZZZ';

    private const SCOPE       = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const API_BASE    = 'https://searchconsole.googleapis.com/webmasters/v3';
    private const MAX_ROWS    = 25000;
    private const MAX_RANGE   = 490;

    private bool $enabled;
    private string $keyPath;
    private string $siteProperty;
    private int $dataLagDays;
    private int $timeout;
    private int $lastHttpStatus = 0;

    public function __construct(
        ?bool $enabled = null,
        ?string $keyPath = null,
        ?string $siteProperty = null,
        ?int $dataLagDays = null,
        ?int $timeout = null,
    ) {
        $this->enabled      = $enabled ?? self::envBool('SEARCH_CONSOLE_ENABLED');
        $this->keyPath      = trim($keyPath ?? self::envString('SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH'));
        $this->siteProperty = trim($siteProperty ?? self::envString('SEARCH_CONSOLE_SITE_PROPERTY'));
        $this->dataLagDays  = $dataLagDays ?? (int) (self::envString('SEARCH_CONSOLE_DATA_LAG_DAYS') ?: '3');
        $this->timeout      = $timeout ?? (int) (self::envString('SEARCH_CONSOLE_TIMEOUT_SECONDS') ?: '30');
    }

    public function providerName(): string
    {
        return 'google_search_console';
    }

    public function siteProperty(): string
    {
        return $this->siteProperty;
    }

    /**
     * A copy of this connector bound to a specific property.
     *
     * Stored connections (reach_analytics_connections.site_property) are the
     * source of truth once a property is registered; the env value is only the
     * bootstrap default. Credentials stay env-only either way — a property name
     * is not a secret, a service-account key is.
     */
    public function forSiteProperty(string $siteProperty): self
    {
        $siteProperty = trim($siteProperty);
        if ($siteProperty === '' || $siteProperty === $this->siteProperty) {
            return $this;
        }

        return new self(
            enabled: $this->enabled,
            keyPath: $this->keyPath,
            siteProperty: $siteProperty,
            dataLagDays: $this->dataLagDays,
            timeout: $this->timeout,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * True only when the connector could actually perform a call.
     */
    public function isConfigured(): bool
    {
        if (! $this->enabled || $this->siteProperty === '') {
            return false;
        }

        return GoogleServiceAccountToken::inspect($this->keyPath)['parsable'];
    }

    /**
     * Machine-readable configuration state used by the Blog Command Centre so the
     * UI can distinguish "switched off" from "broken" from "no data yet".
     *
     * @return array{status: string, detail: string, site_property: string, client_email: ?string}
     */
    public function configurationState(): array
    {
        if (! $this->enabled) {
            return [
                'status'        => self::STATUS_DISABLED,
                'detail'        => 'SEARCH_CONSOLE_ENABLED is false.',
                'site_property' => '',
                'client_email'  => null,
            ];
        }

        $inspection = GoogleServiceAccountToken::inspect($this->keyPath);

        if (! $inspection['path_configured']) {
            $detail = 'SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH is not set.';
        } elseif (! $inspection['file_exists']) {
            $detail = 'Service-account key file does not exist at the configured path.';
        } elseif (! $inspection['file_readable']) {
            $detail = 'Service-account key file is not readable by the web/CLI user.';
        } elseif (! $inspection['parsable']) {
            $detail = 'Service-account key file is missing private_key or client_email.';
        } elseif ($this->siteProperty === '') {
            $detail = 'SEARCH_CONSOLE_SITE_PROPERTY is not set.';
        } else {
            return [
                'status'        => 'configured',
                'detail'        => 'Credentials present; run a health check to confirm property access.',
                'site_property' => $this->siteProperty,
                'client_email'  => $inspection['client_email'],
            ];
        }

        return [
            'status'        => self::STATUS_MISCONFIGURED,
            'detail'        => $detail,
            'site_property' => $this->siteProperty,
            'client_email'  => $inspection['client_email'],
        ];
    }

    public function healthCheck(): ConnectorHealthResult
    {
        if (! $this->enabled) {
            return ConnectorHealthResult::failing('Search Console connector is disabled.', self::STATUS_DISABLED);
        }

        $state = $this->configurationState();
        if ($state['status'] !== 'configured') {
            return ConnectorHealthResult::failing($state['detail'], self::STATUS_MISCONFIGURED);
        }

        $start = hrtime(true);

        $token = GoogleServiceAccountToken::get($this->keyPath, self::SCOPE, min(10, $this->timeout));
        if ($token === null) {
            return ConnectorHealthResult::failing(
                'Google rejected the service-account credentials.',
                'AuthFailure',
                401,
            );
        }

        try {
            $this->request('GET', self::API_BASE . '/sites', null, $token);
        } catch (\RuntimeException $e) {
            return ConnectorHealthResult::failing($e->getMessage(), self::STATUS_ERROR, $this->lastHttpStatus);
        }

        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if (! $this->validateSiteProperty($this->siteProperty)) {
            return ConnectorHealthResult::failing(
                'Service account is authenticated but has no access to ' . $this->siteProperty . '.',
                'PermissionDenied',
                403,
            );
        }

        return ConnectorHealthResult::healthy($latencyMs);
    }

    public function getCapabilities(): array
    {
        return [
            'max_date_range_days'   => self::MAX_RANGE,
            'batch_size'            => self::MAX_ROWS,
            'supported_dimensions'  => ['date', 'query', 'page', 'device', 'country', 'searchAppearance'],
            'supports_pagination'   => true,
            'rate_limit_per_minute' => 1200,
            'provider_name'         => $this->providerName(),
            'data_lag_days'         => $this->dataLagDays,
        ];
    }

    /**
     * Fetch one page of search analytics rows.
     *
     * Returns MetricBatch::empty() rather than throwing when the connector is not
     * usable, with warningMessage explaining exactly why.
     */
    public function fetchSearchMetrics(IngestionRequest $request): MetricBatch
    {
        if (! $this->enabled) {
            return $this->emptyWith('Search Console connector is disabled.');
        }

        $state = $this->configurationState();
        if ($state['status'] !== 'configured') {
            return $this->emptyWith($state['detail']);
        }

        $token = GoogleServiceAccountToken::get($this->keyPath, self::SCOPE, min(10, $this->timeout));
        if ($token === null) {
            throw new \RuntimeException('google_search_console: service-account authentication failed.');
        }

        [$dateFrom, $dateTo] = $this->resolveDateWindow($request->dateFrom, $request->dateTo);
        if ($dateFrom > $dateTo) {
            return $this->emptyWith(
                'Requested window is entirely inside the Search Console ' . $this->dataLagDays . '-day data lag.',
            );
        }

        $dimensions = $this->resolveDimensions($request->dimensions);
        $rowLimit   = max(1, min($request->batchSize, self::MAX_ROWS));
        $startRow   = (int) ($request->cursorState['start_row'] ?? 0);

        $body = [
            'startDate'  => $dateFrom,
            'endDate'    => $dateTo,
            'dimensions' => $dimensions,
            'rowLimit'   => $rowLimit,
            'startRow'   => $startRow,
            'dataState'  => 'final',
        ];

        $url = self::API_BASE . '/sites/' . rawurlencode($this->siteProperty) . '/searchAnalytics/query';

        $response = $this->request('POST', $url, $body, $token);
        $rawRows  = is_array($response['rows'] ?? null) ? $response['rows'] : [];
        $rows     = [];

        foreach ($rawRows as $row) {
            $rows[] = $this->normaliseRow($row, $dimensions, $dateFrom);
        }

        $returned   = count($rows);
        $isComplete = $returned < $rowLimit;
        $nextCursor = $isComplete ? null : ['start_row' => $startRow + $returned];

        return new MetricBatch(
            rows: $rows,
            nextCursorState: $nextCursor,
            providerFreshnessAt: $dateTo . ' 00:00:00',
            rowCount: $returned,
            isComplete: $isComplete,
            warningMessage: $returned === 0 && $startRow === 0
                ? 'Search Console returned no rows for ' . $dateFrom . '..' . $dateTo . '.'
                : null,
        );
    }

    /**
     * @return string[] site properties the service account can read
     */
    public function getSiteProperties(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $token = GoogleServiceAccountToken::get($this->keyPath, self::SCOPE, min(10, $this->timeout));
        if ($token === null) {
            return [];
        }

        try {
            $response = $this->request('GET', self::API_BASE . '/sites', null, $token);
        } catch (\RuntimeException) {
            return [];
        }

        $properties = [];
        foreach (($response['siteEntry'] ?? []) as $entry) {
            $url = $entry['siteUrl'] ?? null;
            if (is_string($url) && $url !== '') {
                $properties[] = $url;
            }
        }

        return $properties;
    }

    public function validateSiteProperty(string $property): bool
    {
        if ($property === '') {
            return false;
        }

        return in_array($property, $this->getSiteProperties(), true);
    }

    /**
     * Clamp the requested window so unfinalised days are never ingested, and so the
     * range never exceeds the API's 490-day limit.
     *
     * @return array{0: string, 1: string}
     */
    public function resolveDateWindow(string $requestedFrom, string $requestedTo): array
    {
        $maxTo = (new \DateTimeImmutable('today'))
            ->modify('-' . max(0, $this->dataLagDays) . ' days')
            ->format('Y-m-d');

        $to   = min($requestedTo, $maxTo);
        $from = $requestedFrom;

        $earliest = (new \DateTimeImmutable($to))
            ->modify('-' . (self::MAX_RANGE - 1) . ' days')
            ->format('Y-m-d');

        if ($from < $earliest) {
            $from = $earliest;
        }

        return [$from, $to];
    }

    /**
     * Search Console only accepts a known dimension set; anything else is dropped.
     * 'date' is always requested first so rows can be attributed to a day.
     *
     * @param string[] $requested
     * @return string[]
     */
    public function resolveDimensions(array $requested): array
    {
        $allowed = ['date', 'query', 'page', 'device', 'country', 'searchAppearance'];
        $mapped  = [];

        foreach ($requested as $dimension) {
            $normalised = $dimension === 'page_url' ? 'page' : (string) $dimension;
            if (in_array($normalised, $allowed, true) && ! in_array($normalised, $mapped, true)) {
                $mapped[] = $normalised;
            }
        }

        if (! in_array('date', $mapped, true)) {
            array_unshift($mapped, 'date');
        }

        if ($mapped === ['date']) {
            $mapped = ['date', 'page', 'query'];
        }

        return $mapped;
    }

    /**
     * Map a Search Console row onto the internal metric row shape.
     *
     * @param array<string, mixed> $row
     * @param string[]             $dimensions
     * @return array<string, mixed>
     */
    public function normaliseRow(array $row, array $dimensions, string $fallbackDate): array
    {
        $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
        $byDimension = [];
        foreach ($dimensions as $index => $dimension) {
            $byDimension[$dimension] = $keys[$index] ?? null;
        }

        // Dimension keys double as the fact table's uniqueness key. NULLs are
        // never equal to each other in a Postgres unique index, so an absent
        // dimension must become an explicit sentinel or re-ingesting the same
        // day would append duplicate rows instead of updating them.
        $device = strtoupper(trim((string) ($byDimension['device'] ?? '')));
        if (! in_array($device, ['DESKTOP', 'MOBILE', 'TABLET', 'SMARTTV'], true)) {
            $device = 'UNKNOWN';
        }

        // Search Console reports countries as ISO-3166-1 alpha-3 ("ind", "usa").
        $country = strtoupper(trim((string) ($byDimension['country'] ?? '')));
        if (! preg_match('/^[A-Z]{3}$/', $country)) {
            $country = self::COUNTRY_UNKNOWN;
        }

        return [
            'metric_date'  => $byDimension['date'] ?? $fallbackDate,
            'page_url'     => $byDimension['page'] ?? null,
            'query'        => (string) ($byDimension['query'] ?? ''),
            'device'       => $device,
            'country'      => $country,
            'clicks'       => (int) ($row['clicks'] ?? 0),
            'impressions'  => (int) ($row['impressions'] ?? 0),
            'ctr'          => round((float) ($row['ctr'] ?? 0), 6),
            'avg_position' => round((float) ($row['position'] ?? 0), 2),
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws \RuntimeException with a message that never contains the bearer token
     */
    private function request(string $method, string $url, ?array $body, string $token): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_UNESCAPED_SLASHES));
        }

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->lastHttpStatus = $status;

        if ($errno !== CURLE_OK) {
            throw new \RuntimeException('google_search_console: transport error: ' . $this->redact($errmsg));
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('google_search_console: malformed JSON response (HTTP ' . $status . ').');
        }

        if ($status === 429) {
            throw new \RuntimeException('google_search_console: quota exceeded (HTTP 429).');
        }

        if ($status >= 400) {
            $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new \RuntimeException('google_search_console: ' . $this->redact((string) $message));
        }

        return $decoded;
    }

    private function emptyWith(string $warning): MetricBatch
    {
        return new MetricBatch(
            rows: [],
            nextCursorState: null,
            providerFreshnessAt: date('Y-m-d H:i:s'),
            rowCount: 0,
            isComplete: true,
            warningMessage: $warning,
        );
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
