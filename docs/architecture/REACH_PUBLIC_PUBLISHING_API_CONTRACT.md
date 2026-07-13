# Reach — Public Publishing API Contract

**Version:** 1
**Base path:** `/reach/v1/`
**Target:** `aicountly.com` (public website)
**Consumer:** `reach-aicountly` via `AicountlyPublicSitePublisher`

---

## 1. Overview

Reach communicates with the public website through a versioned, HMAC-signed service-to-service HTTP API. Reach never writes directly to the public-site database. Every mutating request must be authenticated, signed, timestamped, and carry a unique nonce and an idempotency key.

The public site is authoritative for canonical URLs, rendering, structured-data output, and sitemap inclusion. Reach is authoritative for content approval, version identity, and payload checksums.

---

## 2. Authentication and Request Signing

### 2.1 Required headers on every mutating request

```
Authorization:         Bearer <AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN>
X-Reach-Key-Id:        <AICOUNTLY_PUBLIC_SITE_KEY_ID>
X-Reach-Timestamp:     <unix_seconds_utc>
X-Reach-Nonce:         <uuid_v4>
X-Reach-Signature:     <hmac_sha256_hex>
X-Reach-Content-SHA256:<sha256_hex_of_raw_request_body>
X-Request-ID:          <reach_request_id>
X-Idempotency-Key:     <idempotency_key>
X-Reach-API-Version:   1
Content-Type:          application/json
```

### 2.2 Canonical string for HMAC-SHA256 signature

```
UPPERCASE_HTTP_METHOD\n
/reach/v1/path/to/endpoint\n
unix_timestamp\n
nonce_uuid\n
sha256_hex_of_raw_body\n
idempotency_key\n
api_version
```

Algorithm: `HMAC-SHA256(AICOUNTLY_PUBLIC_SITE_SIGNING_KEY, canonical_string)`

The signature is compared using a timing-safe comparison function on the public site.

### 2.3 Replay protection

The public site must reject requests where:
- `X-Reach-Timestamp` is more than 60 seconds from server time
- `X-Reach-Nonce` has been seen in the last 300 seconds (stored in `reach_api_nonces`)
- `X-Reach-Signature` does not match the recomputed signature
- `X-Reach-Content-SHA256` does not match `hash('sha256', $rawBody)`
- `X-Reach-Key-Id` is unknown
- `X-Reach-API-Version` is unsupported

### 2.4 Idempotency

For any given `X-Idempotency-Key`:
- If the identical operation has already been accepted, return the original response (HTTP 200) with `idempotent_replay: true`
- If the same key is presented with a conflicting payload checksum, return HTTP 409

---

## 3. Endpoints

### 3.1 Create draft

```
POST /reach/v1/content/drafts
```

Creates a new content record in draft status on the public site.

**Request payload fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `api_version` | int | yes | Must be 1 |
| `operation` | string | yes | `create_draft` |
| `reach_content_id` | bigint | yes | Reach internal ID |
| `reach_content_uuid` | uuid | yes | Reach external UUID |
| `reach_content_version_id` | bigint | yes | Version being published |
| `reach_content_version_number` | int | yes | Monotonic version counter |
| `content_type` | string | yes | `blog` or `knowledge_base` |
| `idempotency_key` | string | yes | |
| `request_id` | string | yes | |
| `timestamp` | int | yes | Unix UTC |
| `nonce` | string | yes | UUID v4 |
| `payload_checksum` | string | yes | SHA-256 of `payload` JSON |
| `publication_target` | string | yes | e.g. `aicountly_com_blog` |
| `payload` | object | yes | Type-specific content |

**Response:**

| Field | Type | Notes |
|-------|------|-------|
| `success` | bool | |
| `operation` | string | `create_draft` |
| `public_content_id` | bigint | Public site's internal ID |
| `public_content_uuid` | uuid | |
| `public_status` | string | `draft` |
| `received_reach_version` | int | Echo of `reach_content_version_number` |
| `payload_checksum` | string | Echo of submitted checksum |
| `request_id` | string | |

---

### 3.2 Update draft

```
PUT /reach/v1/content/drafts/{publicContentId}
```

Replaces draft content. Rejects if `reach_content_version_number` is not greater than stored.

---

### 3.3 Publish

```
POST /reach/v1/content/{publicContentId}/publish
```

Moves draft to published status immediately.

**Response adds:**

| Field | Type | Notes |
|-------|------|-------|
| `public_status` | string | `published` |
| `canonical_url` | string | Absolute canonical URL |
| `public_version` | int | Public site version counter |
| `published_at` | string | ISO 8601 UTC |

---

### 3.4 Schedule

```
POST /reach/v1/content/{publicContentId}/schedule
```

Schedules publication for a future UTC timestamp.

**Additional request fields:** `scheduled_at` (ISO 8601 UTC)

**Response adds:** `scheduled_at`, `public_status: scheduled`

---

### 3.5 Unpublish

```
POST /reach/v1/content/{publicContentId}/unpublish
```

Moves content to draft/hidden status. Requires `reason`.

---

### 3.6 Restore

```
POST /reach/v1/content/{publicContentId}/restore
```

Restores previously unpublished content.

---

### 3.7 Get status

```
GET /reach/v1/content/{publicContentId}
```

Returns current public status without authentication risk to callers — still requires service auth.

---

### 3.8 Get by external ID

```
GET /reach/v1/content/by-external-id/{reachContentUuid}
```

Looks up by Reach UUID.

---

### 3.9 Publication status

```
GET /reach/v1/content/{publicContentId}/publication-status
```

Returns lightweight status object.

---

### 3.10 Verification data

```
GET /reach/v1/content/{publicContentId}/verification
```

Returns the data Reach needs to verify publication:

| Field | Type | Notes |
|-------|------|-------|
| `public_status` | string | |
| `canonical_url` | string | |
| `public_version` | int | |
| `payload_checksum` | string | Checksum stored on public site |
| `reach_content_version` | int | Echoed reach version |
| `title` | string | Rendered title |
| `body_hash` | string | SHA-256 of sanitised body text |
| `structured_data_types` | array | Schema types present |
| `sitemap_status` | string | `included`, `excluded`, `unknown` |
| `robots_directive` | string | |
| `updated_at` | string | |

---

### 3.11 Trigger verification

```
POST /reach/v1/content/{publicContentId}/verify
```

Asks the public site to re-evaluate and return the verification data. Same response shape as 3.10.

---

### 3.12 Health check

```
GET /reach/v1/health
```

No auth required. Returns `{"ok": true, "api_version": 1}`.

---

## 4. Standard Response Envelope

All responses follow:

```json
{
  "success": true,
  "operation": "publish",
  "public_content_id": 42,
  "public_content_uuid": "...",
  "public_status": "published",
  "canonical_url": "https://aicountly.com/blog/example-article",
  "public_version": 3,
  "received_reach_version": 7,
  "scheduled_at": null,
  "published_at": "2026-07-13T10:00:00Z",
  "updated_at": "2026-07-13T10:00:00Z",
  "sitemap_status": "included",
  "verification_status": "verified",
  "payload_checksum": "abc123...",
  "request_id": "reach-prod-xyz",
  "idempotent_replay": false,
  "error_code": null,
  "safe_error_message": null
}
```

Error response:

```json
{
  "success": false,
  "error_code": "signature_error",
  "safe_error_message": "Request signature could not be verified.",
  "request_id": "reach-prod-xyz"
}
```

---

## 5. Error Codes

| Code | HTTP | Retryable | Notes |
|------|------|-----------|-------|
| `configuration_error` | 500 | No | Missing env vars on public site |
| `authentication_error` | 401 | No | Invalid bearer credential |
| `signature_error` | 401 | No | HMAC mismatch |
| `replay_rejected` | 401 | No | Expired timestamp or reused nonce |
| `rate_limited` | 429 | Yes | Retry after `Retry-After` header |
| `validation_error` | 422 | No | Payload schema violation |
| `version_conflict` | 409 | No | Reach version older than stored |
| `checksum_mismatch` | 409 | No | Payload checksum mismatch |
| `not_found` | 404 | No | Content ID not found |
| `timeout` | 504 | Yes | |
| `network_error` | — | Yes | Connection-level failure |
| `server_error` | 500 | Yes | Transient 5xx |
| `publication_blocked` | 403 | No | Content violates site policy |
| `verification_failed` | 200 | Conditional | Verification data returned but check failed |
| `unsupported_version` | 400 | No | `X-Reach-API-Version` unknown |

---

## 6. Rate Limits

| Operation | Limit |
|-----------|-------|
| Mutating requests | 60 per minute per key ID |
| GET requests | 120 per minute per key ID |
| Health check | Unlimited |

Rate limit exceeded: HTTP 429 with `Retry-After: <seconds>`.

---

## 7. Versioning Policy

- Major version in path (`/reach/v1/`)
- Minor additions are backward-compatible within major version
- Breaking changes increment major version and open a new path
- Old major version supported for 90 days after new version launch
- Version mismatch: HTTP 400 `unsupported_version`

---

## 8. Blog Payload Example

```json
{
  "api_version": 1,
  "operation": "create_draft",
  "reach_content_id": 101,
  "reach_content_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "reach_content_version_id": 305,
  "reach_content_version_number": 4,
  "content_type": "blog",
  "idempotency_key": "reach-101-v4-create",
  "request_id": "reach-prod-abc123",
  "timestamp": 1752393600,
  "nonce": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "payload_checksum": "a3f2d1...",
  "publication_target": "aicountly_com_blog",
  "payload": {
    "title": "How AICOUNTLY Simplifies Statutory Reporting",
    "slug": "aicountly-statutory-reporting",
    "excerpt": "A concise guide to statutory compliance...",
    "body_html": "<h2>Introduction</h2><p>...</p>",
    "meta_title": "Statutory Reporting Made Simple | AICOUNTLY Blog",
    "meta_description": "Learn how AICOUNTLY automates statutory filing...",
    "canonical_preference": "self_canonical",
    "robots_directive": "index,follow",
    "category": "compliance",
    "tags": ["statutory", "compliance", "reporting"],
    "author_name": "Rahul Sharma",
    "author_email": "rahul@aicountly.com",
    "reviewer_name": "Priya Verma",
    "featured_image_url": "https://cdn.aicountly.com/blog/statutory-reporting.webp",
    "featured_image_alt": "Abstract illustration of statutory compliance documents",
    "internal_links": [
      {"anchor": "Smart Books", "url": "https://aicountly.com/smart-books", "rel": "nofollow"}
    ],
    "citations": [
      {"label": "MCA Companies Act 2013", "url": "https://www.mca.gov.in/MinistryV2/companyact.html"}
    ],
    "faq": [
      {"question": "What is statutory reporting?", "answer": "Statutory reporting refers to..."}
    ],
    "structured_data": [
      {"@context": "https://schema.org", "@type": "BlogPosting", "headline": "..."}
    ],
    "language": "en",
    "scheduled_at": null
  }
}
```

---

## 9. Knowledge-Base Payload Example

```json
{
  "api_version": 1,
  "operation": "create_draft",
  "reach_content_id": 202,
  "reach_content_uuid": "660e8400-e29b-41d4-a716-446655440001",
  "reach_content_version_id": 410,
  "reach_content_version_number": 2,
  "content_type": "knowledge_base",
  "idempotency_key": "reach-202-v2-create",
  "request_id": "reach-prod-def456",
  "timestamp": 1752393700,
  "nonce": "a47ac10b-58cc-4372-a567-0e02b2c3d480",
  "payload_checksum": "b4e3f2...",
  "publication_target": "aicountly_com_kb",
  "payload": {
    "title": "How to Set Up Automated Bank Reconciliation",
    "slug": "automated-bank-reconciliation-setup",
    "article_type": "how_to",
    "summary": "Step-by-step setup guide for automated bank reconciliation in Smart Books.",
    "body_html": "<h2>Prerequisites</h2><p>...</p>",
    "product": "smart_books",
    "module": "banking",
    "feature": "auto_reconciliation",
    "applicable_versions": {"type": "all_current_versions"},
    "availability_status": "available",
    "difficulty_level": "intermediate",
    "estimated_completion_minutes": 15,
    "prerequisites": ["Active Smart Books subscription", "Bank account connected"],
    "steps": [
      {"step_number": 1, "title": "Navigate to Banking", "description": "...", "expected_outcome": "Banking module opens"},
      {"step_number": 2, "title": "Enable Auto-Reconciliation", "description": "...", "expected_outcome": "Toggle turns green"}
    ],
    "troubleshooting": [
      {"symptom": "Reconciliation fails", "cause": "API credentials expired", "resolution": "Re-link bank account"}
    ],
    "related_articles": [
      {"title": "Manual Bank Reconciliation", "slug": "manual-bank-reconciliation"}
    ],
    "meta_title": "Automated Bank Reconciliation Setup | AICOUNTLY Help",
    "meta_description": "Learn how to configure automated bank reconciliation in Smart Books.",
    "canonical_preference": "self_canonical",
    "robots_directive": "index,follow",
    "structured_data": [
      {"@context": "https://schema.org", "@type": "HowTo", "name": "..."}
    ],
    "language": "en"
  }
}
```

---

## 10. Verification Response Example

```json
{
  "success": true,
  "operation": "verify",
  "public_content_id": 42,
  "public_status": "published",
  "canonical_url": "https://aicountly.com/blog/aicountly-statutory-reporting",
  "public_version": 4,
  "payload_checksum": "a3f2d1...",
  "reach_content_version": 4,
  "title": "How AICOUNTLY Simplifies Statutory Reporting",
  "body_hash": "e5f6a7...",
  "structured_data_types": ["BlogPosting"],
  "sitemap_status": "included",
  "robots_directive": "index,follow",
  "updated_at": "2026-07-13T10:05:00Z",
  "request_id": "reach-prod-abc123"
}
```

---

## 11. Security Requirements

1. Secrets (`AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN`, `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY`) from environment only — never in DB, never logged, never exposed to frontend
2. HMAC uses `hash_hmac('sha256', $canonicalString, $signingKey)` — constant-time comparison on both sides
3. Nonces stored with TTL index; purge entries older than 5 minutes
4. Maximum request body: 2 MB
5. HTML sanitised again on receipt (HTMLPurifier or equivalent)
6. Structured-data types: allow-list only (9 approved types)
7. All URLs in payload: SSRF-safe domain check before storage
8. Service auth middleware must not be reachable via ordinary user session
9. `REACH_PUB_MOCK=true` forces `MockPublicSitePublisher` — automated tests must always set this

---

## 12. Public-Site Environment Variables Required

```
REACH_SERVICE_TOKEN=          # Must match AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN
REACH_SIGNING_KEY=            # Must match AICOUNTLY_PUBLIC_SITE_SIGNING_KEY
REACH_KEY_ID=reach-v1         # Must match AICOUNTLY_PUBLIC_SITE_KEY_ID
REACH_TIMESTAMP_TOLERANCE=60  # Seconds
REACH_NONCE_TTL=300           # Seconds
REACH_MAX_BODY_BYTES=2097152  # 2 MB
```
