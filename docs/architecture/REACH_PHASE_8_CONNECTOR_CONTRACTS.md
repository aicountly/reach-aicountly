# Phase 8 Connector Contracts

**Phase:** 8  
**Location:** `server-php/app/Libraries/Intelligence/Connectors/`

---

## Connector Interface Standard

All Phase 8 connectors implement a common pattern:

```php
interface BaseConnectorInterface {
    public function providerName(): string;
    public function isEnabled(): bool;
    public function healthCheck(): ConnectorHealthResult;
    public function getCapabilities(): array;
}
```

---

## SearchConsoleConnectorInterface

```php
interface SearchConsoleConnectorInterface extends BaseConnectorInterface {
    public function fetchSearchMetrics(IngestionRequest $request): MetricBatch;
    public function getSiteProperties(): array;
    public function validateSiteProperty(string $property): bool;
}
```

**Config env keys:**
- `SEARCH_CONSOLE_ENABLED` (bool, default false)
- `SEARCH_CONSOLE_PROPERTY` (site URL, e.g. `sc-domain:aicountly.com`)
- `SEARCH_CONSOLE_CREDENTIAL_REFERENCE` (path reference, never raw JSON)
- `SEARCH_CONSOLE_BACKFILL_DAYS` (int, max 16 months = 490)
- `SEARCH_CONSOLE_BATCH_SIZE` (int, default 25000)

**Data retained:**
- Clicks, impressions, CTR, average position per page/query/date/device/country
- No raw personal search query data beyond what is factually provided and retention-compliant

**Mock:** `MockSearchConsoleConnector` — returns deterministic fixtures with pagination simulation

---

## ContentAnalyticsConnectorInterface

```php
interface ContentAnalyticsConnectorInterface extends BaseConnectorInterface {
    public function fetchContentMetrics(IngestionRequest $request): MetricBatch;
    public function resolvePageToIdentity(string $pagePath): ?string;
    public function listAvailableProperties(): array;
}
```

**Config env keys:**
- `CONTENT_ANALYTICS_ENABLED` (bool, default false)
- `CONTENT_ANALYTICS_PROVIDER` (ga4, default)
- `CONTENT_ANALYTICS_PROPERTY_ID` (GA4 property ID)
- `CONTENT_ANALYTICS_CREDENTIAL_REFERENCE` (path reference)
- `CONTENT_ANALYTICS_BACKFILL_DAYS` (int, default 90)

**Extends:** Existing `Ga4AnalyticsClient` for data retrieval; wraps in connector abstraction

**Mock:** `MockContentAnalyticsConnector` — deterministic per-page metrics, simulates missing mappings

---

## IndexNowConnectorInterface

```php
interface IndexNowConnectorInterface extends BaseConnectorInterface {
    public function submitUrl(string $url, string $key): IndexNowReceipt;
    public function submitBatch(array $urls, string $key): IndexNowReceipt;
    public function validateKeyLocation(string $keyUrl): bool;
}
```

**Config env keys:**
- `INDEXNOW_ENABLED` (bool, default false)
- `INDEXNOW_ENDPOINT` (URL, e.g. `https://api.indexnow.org/indexnow`)
- `INDEXNOW_KEY_REFERENCE` (env-only, never in source)
- `INDEXNOW_KEY_LOCATION` (public URL where key file is served)
- `INDEXNOW_BATCH_SIZE` (int, default 100, max 10000)

**SSRF protection:** Endpoint must match allowlisted hosts (`api.indexnow.org`, `www.bing.com`, `search.google.com`)

**Mock:** `MockIndexNowConnector` — records calls, simulates rate limit and timeout scenarios

---

## DTO Contracts

### IngestionRequest
```php
final class IngestionRequest {
    public string $connectionId;
    public string $streamType;       // search_metrics | content_metrics
    public string $dateFrom;         // YYYY-MM-DD UTC
    public string $dateTo;           // YYYY-MM-DD UTC
    public array  $dimensions;       // provider-specific
    public int    $batchSize;
    public ?array $cursorState;      // provider pagination token
}
```

### MetricBatch
```php
final class MetricBatch {
    public array  $rows;             // normalised metric rows
    public ?array $nextCursorState;  // null if complete
    public string $providerFreshnessAt;
    public int    $rowCount;
    public bool   $isComplete;
}
```

### ConnectorCursor
```php
final class ConnectorCursor {
    public string  $connectionId;
    public string  $streamType;
    public string  $lastIngestedDate;
    public ?string $backfillFromDate;
    public ?array  $cursorState;
}
```

---

## Credential Rules

1. Never store raw service-account JSON in the database
2. Use `CREDENTIAL_REFERENCE` env keys that point to a file path or secret manager reference
3. Credential objects must implement `redact()` before logging
4. Frontend may only receive connection status (enabled/disabled/health), never credential values
5. Connections must support `disable()` and `revoke()` operations
6. Minimum OAuth scopes: GSC = `webmasters.readonly`, GA4 = `analytics.readonly`

---

## Error Classification

```php
enum ConnectorErrorClass {
    case Transient;    // retry with backoff
    case RateLimit;    // retry after provider-specified delay
    case AuthFailure;  // disable connector, alert
    case QuotaExceeded;// back off until quota resets
    case Malformed;    // log, skip batch, continue
    case Permanent;    // dead-letter, alert
}
```
