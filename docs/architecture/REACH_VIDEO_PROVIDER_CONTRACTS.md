# Reach Video Provider Contracts

**Prepared:** 2026-07-14
**Repository:** reach-aicountly
**Phase:** 6

This document is the authoritative specification for all provider contracts in the Phase 6 Video Content Automation system. Any new provider implementation must fulfil every method contract described here.

---

## 1. RenderProviderInterface

**File:** `app/Libraries/Video/Providers/RenderProviderInterface.php`

### Purpose

Abstracts the render backend so that mock, staging, and production render systems can be swapped via `VIDEO_RENDER_PROVIDER` without changing service code.

### Method contracts

#### `queue(array $job): RenderReceipt`

Submit a render job to the provider.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `job.render_job_uuid` | string | yes | Internal UUID of the `reach_video_render_jobs` record |
| `job.project_uuid` | string | yes | Internal UUID of the owning project |
| `job.script_version_uuid` | string | yes | UUID of the approved script version |
| `job.render_profile` | array | yes | Resolved render profile config (resolution, fps, bitrate, format) |
| `job.asset_urls` | array | no | Pre-signed source asset URLs if available |
| `job.idempotency_key` | string | yes | Unique key; provider must treat duplicate as no-op and return same receipt |

**Returns:** `RenderReceipt` value object:
```php
{
  provider_job_id: string,  // provider's opaque job identifier
  queued_at: \DateTimeImmutable,
  estimated_duration_seconds: ?int,
  receipt_raw: array         // full provider response for audit storage
}
```

**Throws:**
- `ProviderNotConfiguredException` — provider not enabled via config
- `ProviderRateLimitException` — provider rate limit exceeded (caller should backoff)
- `ProviderInvalidRequestException` — job payload rejected (caller should not retry)
- `ProviderTransientException` — transient failure (caller may retry with backoff)

#### `status(string $providerJobId): RenderStatus`

Query the current status of a provider job.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `$providerJobId` | string | yes | Value returned by `queue()` |

**Returns:** `RenderStatus` value object:
```php
{
  state: 'queued'|'rendering'|'rendered'|'failed'|'cancelled',
  progress_pct: ?int,
  failure_reason: ?string,
  output_url: ?string,      // pre-signed download URL when state='rendered'
  completed_at: ?\DateTimeImmutable,
  status_raw: array
}
```

#### `cancel(string $providerJobId): bool`

Request cancellation of a queued or rendering job.

Returns `true` if cancellation was accepted by the provider. Returns `false` if the job was already in a terminal state. Does not throw on `not found` — returns `false`.

#### `getCapabilities(): array`

Returns an associative array describing provider capabilities:

```php
[
  'max_resolution'     => '3840x2160',
  'supported_formats'  => ['mp4', 'webm'],
  'max_duration_secs'  => 3600,
  'max_asset_bytes'    => 5368709120,
  'supports_callback'  => true,
  'supports_polling'   => true,
]
```

---

## 2. MockRenderProvider

**File:** `app/Libraries/Video/Providers/MockRenderProvider.php`

**Selected when:** `CI_ENVIRONMENT=testing` OR `VIDEO_RENDER_PROVIDER=mock`

### Behaviour specification

`MockRenderProvider` uses the `job.render_profile.mock_scenario` key (defaulting to `success`) to determine its response. All scenarios are deterministic (no randomness).

| Scenario key | `queue()` result | `status()` result | Notes |
|---|---|---|---|
| `success` | Returns receipt | Returns `rendered`; `output_url` = `mock://rendered/{uuid}` | Default |
| `timeout` | Returns receipt | Returns `rendering` indefinitely | Simulates hung job |
| `rate_limit` | Throws `ProviderRateLimitException` | — | Caller must backoff |
| `invalid_request` | Throws `ProviderInvalidRequestException` | — | Caller must not retry |
| `error` | Returns receipt | Returns `failed`; `failure_reason` = `mock_provider_error` | |
| `callback_replay` | Returns same receipt for same `idempotency_key` | Returns `rendered` | Tests dedup |
| `cancel_success` | Returns receipt | Returns `rendering` until `cancel()` | `cancel()` returns true |

**Idempotency:** If `queue()` is called with the same `idempotency_key` twice, the second call returns the same `RenderReceipt` as the first without creating a new record.

---

## 3. YouTubePublisherInterface

**File:** `app/Libraries/Video/Providers/YouTubePublisherInterface.php`

### Purpose

Abstracts all outbound YouTube operations so mock and live implementations share the same contract. The mock is the CI default; live requires `YOUTUBE_PUBLISHING_ENABLED=true`.

### Method contracts

#### `upload(array $payload): YouTubeUploadReceipt`

Upload a video file to YouTube.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `payload.project_uuid` | string | yes | For idempotency and audit correlation |
| `payload.video_asset_url` | string | yes | Pre-signed URL for the rendered video asset |
| `payload.idempotency_key` | string | yes | Unique key per upload attempt |
| `payload.connection_id` | int | yes | FK to `reach_publication_connections.id` |

**Returns:** `YouTubeUploadReceipt`:
```php
{
  remote_video_id: string,   // YouTube video ID (or mock equivalent)
  upload_status: string,     // 'uploaded', 'processing', 'rejected', 'failed'
  upload_url: ?string,       // YouTube watch URL once processing complete
  idempotency_key: string,
  receipt_raw: array
}
```

**Throws:**
- `YouTubeAuthException` — OAuth token invalid/expired; surface to user
- `YouTubeQuotaException` — API quota exceeded; retry after 24 h
- `YouTubeRejectionException` — content rejected by YouTube (do not retry)
- `YouTubeTransientException` — transient failure (safe to retry with backoff)

#### `setMetadata(string $remoteVideoId, array $metadata): bool`

Set video title, description, tags, category, and privacy status.

| Field | Type | Required | Constraint |
|---|---|---|---|
| `metadata.title` | string | yes | max 100 chars |
| `metadata.description` | string | no | max 5000 chars |
| `metadata.tags` | string[] | no | max 500 chars total |
| `metadata.category_id` | string | no | YouTube category ID |
| `metadata.privacy_status` | `public`/`unlisted`/`private` | yes | Default: `private` |

Returns `true` on success. Throws `YouTubeRejectionException` on policy violation.

#### `uploadCaption(string $remoteVideoId, array $caption): string`

Upload a caption track. Returns the YouTube caption track ID.

| Field | Type | Required | Description |
|---|---|---|---|
| `caption.language` | string | yes | BCP-47 language code |
| `caption.name` | string | yes | Display name for the track |
| `caption.content` | string | yes | SRT or VTT formatted caption text |
| `caption.is_default` | bool | no | Default: false |

#### `setThumbnail(string $remoteVideoId, string $imageUrl): bool`

Upload a custom thumbnail. Returns `true` on success.

The `imageUrl` must pass `UrlPolicy::validate()` before this method is called.

**Constraints:**
- JPEG or PNG only
- Max 2 MB
- Min 640px wide
- Aspect ratio 16:9 recommended

#### `getStatus(string $remoteVideoId): YouTubeVideoStatus`

Query processing status of an uploaded video.

**Returns:** `YouTubeVideoStatus`:
```php
{
  remote_video_id: string,
  processing_status: 'uploading'|'processing'|'succeeded'|'failed'|'deleted',
  upload_status: string,
  failure_reason: ?string,
  watch_url: ?string,
  retrieved_at: \DateTimeImmutable
}
```

#### `getReceiptNormalized(array $rawReceipt): array`

Normalise a raw provider receipt into the canonical format used by `reach_publication_verifications.verification_payload`. Implementations may not return receipts with OAuth tokens or client secrets in any field.

---

## 4. MockYouTubePublisher

**File:** `app/Libraries/Video/Providers/MockYouTubePublisher.php`

**Selected when:** `YOUTUBE_PUBLISHING_ENABLED != true` OR `CI_ENVIRONMENT=testing`

### Behaviour specification

| Method | Return value |
|---|---|
| `upload()` | Returns `YouTubeUploadReceipt` with `remote_video_id = "yt-mock-{project_uuid}"` |
| `setMetadata()` | Returns `true` |
| `uploadCaption()` | Returns `"yt-mock-caption-{language}"` |
| `setThumbnail()` | Returns `true` |
| `getStatus()` | Returns `YouTubeVideoStatus` with `processing_status = 'succeeded'` |
| `getReceiptNormalized()` | Returns the input array unchanged |

Idempotency: calling `upload()` with the same `idempotency_key` twice returns the same `YouTubeUploadReceipt`.

---

## 5. VideoProviderFactory

**File:** `app/Libraries/Video/Providers/VideoProviderFactory.php`

### Render provider selection

```
IF CI_ENVIRONMENT === 'testing'          → MockRenderProvider
ELSE IF VIDEO_RENDER_PROVIDER === 'mock' → MockRenderProvider
ELSE IF VIDEO_RENDER_PROVIDER === 'production' → ProductionRenderProvider
ELSE                                     → MockRenderProvider (safe default)
```

### YouTube publisher selection

```
IF CI_ENVIRONMENT === 'testing'          → MockYouTubePublisher
ELSE IF YOUTUBE_PUBLISHING_ENABLED != 'true' → MockYouTubePublisher
ELSE                                     → YouTubePublisher (live)
```

### Usage

```php
$renderProvider = VideoProviderFactory::makeRenderProvider();
$youtubePublisher = VideoProviderFactory::makeYouTubePublisher();
```

Both methods return instances conforming to their respective interfaces. Service code must not depend on the concrete class.

---

## 6. Value object specifications

### RenderReceipt

```php
final class RenderReceipt
{
    public function __construct(
        public readonly string $providerJobId,
        public readonly \DateTimeImmutable $queuedAt,
        public readonly ?int $estimatedDurationSeconds,
        public readonly array $receiptRaw,
    ) {}
}
```

### RenderStatus

```php
final class RenderStatus
{
    public function __construct(
        public readonly string $state,   // 'queued'|'rendering'|'rendered'|'failed'|'cancelled'
        public readonly ?int $progressPct,
        public readonly ?string $failureReason,
        public readonly ?string $outputUrl,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly array $statusRaw,
    ) {}
}
```

### YouTubeUploadReceipt

```php
final class YouTubeUploadReceipt
{
    public function __construct(
        public readonly string $remoteVideoId,
        public readonly string $uploadStatus,
        public readonly ?string $uploadUrl,
        public readonly string $idempotencyKey,
        public readonly array $receiptRaw,
    ) {}
}
```

### YouTubeVideoStatus

```php
final class YouTubeVideoStatus
{
    public function __construct(
        public readonly string $remoteVideoId,
        public readonly string $processingStatus,
        public readonly string $uploadStatus,
        public readonly ?string $failureReason,
        public readonly ?string $watchUrl,
        public readonly \DateTimeImmutable $retrievedAt,
    ) {}
}
```

---

## 7. Exception hierarchy

```
\App\Exceptions\Video\
  VideoProviderException (base)
  ├── ProviderNotConfiguredException   — provider not enabled
  ├── ProviderRateLimitException       — rate limit; caller backoffs
  ├── ProviderInvalidRequestException  — do not retry
  ├── ProviderTransientException       — safe to retry
  ├── YouTubeAuthException             — OAuth token problem
  ├── YouTubeQuotaException            — quota exhausted
  ├── YouTubeRejectionException        — content rejected
  └── YouTubeTransientException        — safe to retry
```

All exceptions carry:
- `$message` — human-readable, safe to log
- `$context` — array with `provider`, `operation`, sanitised request metadata
- `$retryAfterSeconds` — hint for backoff (null = caller decides)

---

## 8. Callback authentication contract

### VideoCallbackAuthenticator

All provider callbacks to `POST v1/video/provider/render-callback` and `POST v1/video/provider/youtube-callback` must pass the following verification:

**Required headers:**
```
X-Signature: sha256={lowercase_hex_hmac}
X-Timestamp: {unix_epoch_integer}
X-Provider-Event-Id: {provider_opaque_event_id}
```

**Verification steps (all must pass):**

1. **Timestamp tolerance:** `|time() - X-Timestamp| <= VIDEO_CALLBACK_TIMESTAMP_TOLERANCE` (default 300 seconds)
2. **HMAC verification:** `hash_hmac('sha256', $rawBody, $hmacKey)` must equal the value after the `sha256=` prefix, compared with `hash_equals()` (timing-safe)
3. **Replay guard:** `SELECT 1 FROM reach_video_provider_events WHERE provider_event_id = ?` — if row exists, return HTTP 409
4. **Deduplication insert:** `INSERT INTO reach_video_provider_events (provider, provider_event_id, received_at)` — then process

On any verification failure: HTTP 401, no processing, no log that reveals HMAC key.

### HMAC key selection

| Provider | Environment variable |
|---|---|
| Render provider | `VIDEO_RENDER_HMAC_KEY` |
| YouTube | `YOUTUBE_WEBHOOK_SECRET` |

Neither key is ever logged, included in API responses, or stored in audit trails.
