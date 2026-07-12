# REACH Security Controls — Phase 0

## Rate limiting

Postgres-backed, cross-worker safe. Table: `reach_rate_limits`
(`bucket_key`, `window_start`, `tokens`, `blocked_hits`, `updated_at`),
unique `(bucket_key, window_start)`.

Policies live in `App\Config\RateLimits::policies()` and are attached to
routes via `filter => throttle:<policy>` in `app/Config/Routes.php`.

| Policy            | Limit / window        | Scope       | Where applied                              |
|-------------------|-----------------------|-------------|--------------------------------------------|
| `auth`            | 10 / 60s              | IP          | All `auth/*` routes                        |
| `public_capture`  | 5 / 60s               | IP          | `public/leads/capture`                     |
| `public_capture_token` | 100 / 3600s      | token       | `public/leads/capture` (X-Reach-Capture-Token) |
| `bot_dispatch`    | 30 / 60s              | user        | `bot/dispatch`                             |
| `approval`        | 60 / 60s              | user        | `approvals/*/decide`, `blog/*/approve/reject` |
| `integration`     | 30 / 60s              | user        | `engage-push/*`, `worker-status/ping`, `jobs/*/retry\|cancel` |

Response body on block:

```json
{ "ok": false, "error": "Too many requests", "retry_after": <secs> }
```

with `Retry-After: <secs>` header. Fired 3 consecutive blocks in a window
triggers the `security.rate_limited` audit event (see below).

Trusted-proxy IP resolution: the filter honours `X-Forwarded-For` **only**
when the immediate peer matches `env('TRUSTED_PROXIES')`. Leave empty on
cPanel unless a load balancer is proven trusted.

## HTML sanitisation

`App\Libraries\HtmlSanitizer` wraps `ezyang/htmlpurifier`. Policy lives
in `App\Config\ContentSanitization`:

- Allowed tags: `p, h1-h4, ul, ol, li, a, strong, em, code, pre, table,
  thead, tbody, tr, th, td, blockquote, hr, br`.
- Allowed attributes: `a.href, a.title, a.rel`, table cell scope/span.
- Allowed URI schemes: `http`, `https`. No `javascript:`, no `data:`, no
  `mailto:`.
- Anchors are stamped `rel="nofollow noopener"` and stripped of `target`.
- Inline styles, event handlers, `<style>`, `<script>`, `<iframe>` removed.
- Max content size: 256 KiB per field.
- Cache directory: `writable/htmlpurifier` (auto-created; falls back to
  in-memory when the FS is read-only).

Wired into:

- `BlogController::store` / `update` — `title`, `excerpt`, `content`,
  `seo_title`, `seo_description`, `category`, `focus_keyword`, `author`.
- `CampaignController::normalize` — `name`, `objective`, UTM fields,
  `creative_copy`.
- `LandingPageController::store` / `update` — `title`, `body`.

Plain-text fields go through `HtmlSanitizer::purifyText()` which strips
_all_ tags rather than allowing a subset.

## URL / SSRF policy

`App\Libraries\UrlPolicy::validate(string $url, array $opts): UrlPolicyResult`
blocks:

- Non-http(s) schemes.
- URLs with embedded userinfo (`user:pass@`).
- Loopback (127/8, ::1).
- Private ranges (10/8, 172.16/12, 192.168/16, fc00::/7, fe80::/10).
- Link-local (169.254/16 → AWS metadata 169.254.169.254).
- Well-known cloud metadata hostnames (`metadata.google.internal`,
  `metadata.aws`, `kubernetes.default.svc*`).
- CGN (100.64/10), multicast, broadcast, TEST-NETs, reserved future.

Hostnames are DNS-resolved (`dns_get_record`) so a public-looking hostname
pointing at 127.0.0.1 is blocked. `URL_POLICY_ALLOWED_HOSTS` (comma list)
and per-call `opts['allowedHosts']` bypass DNS checks for known-good
destinations. Wildcard `.example.org` matches any subdomain.

Wired into:

- `AicountlySitePublisher::publish` — validates the composed endpoint before
  each publish attempt.
- `EngageClient::pushLead` — validates the composed endpoint before each
  push attempt.
- `BlogController::store/update` — validates `canonical_url`.
- `CampaignController::normalize` — validates `landing_page_url`.

Tests: `tests/Unit/UrlPolicyTest.php` covers scheme, loopback, AWS
metadata, GCP metadata, private ranges, userinfo, malformed, IPv6
loopback, and subdomain wildcards.

## Payload validation

- `App\Filters\JsonBodySizeFilter` (`body-size` alias, registered globally):
  rejects POST/PUT/PATCH/DELETE JSON bodies over 1 MiB with HTTP 413.
  Override per-route with `body-size:<bytes>`.
- `App\Libraries\RequestValidator` wraps CI4 `Validation` for controllers
  that need typed rulesets.
- `App\Config\Enums` centralises enum sets (blog status, approval subject,
  campaign status, actor type, job status, rate-limit scope, permission
  mode). Controllers validate against `Enums::isValid()` rather than
  hard-coding accepted values.

## Secret redaction

`App\Libraries\SecretRedactor::redact($value)` deep-walks arrays and
replaces:

1. Values under keys that `App\Config\SensitiveSettings::isSensitive($key)`
   identifies (exact matches + substring heuristics: `token`, `secret`,
   `password`, `api_key`, `authorization`, `private_key`, `service_account`).
2. Bearer-shaped strings (`Bearer <token>`), JWT-shaped tokens
   (`aaa.bbb.ccc`), and long opaque base64/hex blobs (≥ 32 chars).

Used by:

- `AuditLogger::log()` — redacts `old_value`, `new_value`, `metadata`
  before both the local insert and the Console fan-out.
- `JobService::enqueue()` / `markCompleted()` — redacts `payload_json` and
  `result_json`. The `sensitive: true` opt short-circuits payload storage
  to `{__sensitive: true, keys: [...]}`.
- `SettingsController::index()` / `update()` — masks any settings row
  whose `key` is registered in `SensitiveSettings`.

## Extended audit events

Table: `reach_audit_logs`, extended in
`2026-07-12-100031_ExtendReachAuditLogs.php` with:

| Column          | Purpose                                                 |
|-----------------|---------------------------------------------------------|
| `actor_type`    | `human` \| `system` \| `bot` \| `service` (CHECK enforced) |
| `actor_service` | Free-form slug (e.g. `reach:api`, `reach:worker`, `reach:cron`) |
| `reason`        | Approval override / cancel reason (up to 510 chars)     |
| `request_id`    | X-Request-Id at time of event                           |
| `job_id`        | `reach_jobs.id` when event is job-driven                |

Indexes: `(request_id)`, `(job_id)` for fast correlation lookup.

New event action slugs emitted in Phase 0:

| Action                       | Emitted from                                       |
|------------------------------|----------------------------------------------------|
| `permission.denied`          | `PermissionFilter`                                 |
| `approval.decided`           | `ApprovalController::decide`, `BlogController::approve` |
| `approval.overridden`        | Same, when `override: true`                        |
| `approval.policy_denied`     | ApprovalPolicy rejection                           |
| `bot.mode_changed`           | `BotSettingsController::update`                    |
| `bot.dispatched`             | `MarketingBotController::dispatch`                 |
| `publish.attempted`          | `BlogController::publish`                          |
| `campaign.dispatched`        | `CampaignController::setStatus` → `live`           |
| `integration.setting_changed`| `SettingsController::update`                       |
| `job.enqueued`               | `JobService::enqueue`                              |
| `job.reserved`               | `JobService::reserve`                              |
| `job.completed`              | `JobService::markCompleted`                        |
| `job.retried`                | `JobService::markFailed` (retryable) / `retry()`   |
| `job.failed`                 | `JobService::markFailed` (final)                   |
| `job.cancelled`              | `JobService::cancel`                               |
| `security.rate_limited`      | `RateLimitFilter` (after N consecutive blocks)     |

## Correlation IDs

`App\Filters\RequestIdFilter` runs globally (`before` + `after`):

- Trusts `X-Request-Id` from the client when it matches `[A-Za-z0-9._:-]{8,64}`.
- Otherwise generates a UUIDv4 (optionally prefixed with
  `REACH_REQUEST_ID_PREFIX`).
- Exposes on `$request->reachRequestId` and echoes back as `X-Request-Id`.

Propagation:

- **AuditLogger** auto-picks up the request id when the caller doesn't
  pass `requestId:` explicitly.
- **JobService::enqueue** stores the id on the job row; **ReachWork**
  restores it onto `service('request')->reachRequestId` before invoking the
  handler so downstream calls stay correlated.
- **Outbound HTTP** (`AicountlySitePublisher`, `EngageClient`,
  `ConsoleAuditClient`) forwards `X-Request-Id` on every call.
- **Frontend** (`web/src/services/api.js`) generates a
  `reach-web:<uuid>` id per fetch and sets `X-Request-Id`; the response
  header is surfaced on error objects (`err.requestId`) so users can copy
  it into support tickets.

## Configuration checklist

Environment variables introduced in Phase 0
(see `server-php/.env.example`):

- `TRUSTED_PROXIES` — comma-separated list of trusted load-balancer IPs.
- `REACH_REQUEST_ID_PREFIX` — optional label for generated request ids.
- `URL_POLICY_ALLOWED_HOSTS` — hostnames to bypass URL policy checks.
