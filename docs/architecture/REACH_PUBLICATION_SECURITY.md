# Reach Publication Security Architecture

## Overview

This document describes the security controls applied to the Phase 4 publishing pipeline, covering service-to-service authentication, HMAC request signing, replay protection, secret management, and data sanitisation.

---

## Service-to-Service Authentication

All requests from Reach to the `aicountly.com` publishing API are authenticated using HMAC-SHA256 request signing. There is no API key transmitted in plain text outside of the `Authorization` header.

### Required Headers

| Header | Description |
|--------|-------------|
| `Authorization` | `Bearer <service-token>` |
| `X-Reach-Key-Id` | Key identifier (e.g., `reach-v1`) |
| `X-Reach-Timestamp` | Unix timestamp (integer) |
| `X-Reach-Nonce` | UUID v4, unique per request |
| `X-Reach-Signature` | HMAC-SHA256 of canonical string |
| `X-Reach-Content-SHA256` | SHA-256 of request body |
| `X-Idempotency-Key` | UUID v4, unique per publication attempt |
| `X-Reach-API-Version` | API version (`1`) |
| `X-Request-Id` | Correlation ID for tracing |

---

## HMAC-SHA256 Canonical String

```
METHOD\n
path\n
timestamp\n
nonce\n
content-sha256\n
idempotency-key\n
api-version
```

Example:
```
POST
/api/reach/v1/content/drafts
1752400000
550e8400-e29b-41d4-a716-446655440000
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855

1
```

The signature is `HMAC-SHA256(canonical_string, signing_key)`.

---

## Replay Protection

Two mechanisms prevent replayed requests:

### Timestamp Tolerance
- The public site rejects requests where `|now - timestamp| > 60 seconds`.
- Configurable via `REACH_TIMESTAMP_TOLERANCE` env var on the public site.

### Nonce Uniqueness
- Every request must include a unique nonce (UUID v4).
- The public site stores nonces in `reach_api_nonces` (PostgreSQL) with a TTL of 300 seconds.
- Duplicate nonces are rejected with HTTP 401 `replay_rejected`.
- Expired nonces are purged probabilistically (~5% of requests).

---

## Secret Management

| Secret | Storage | Never stored in |
|--------|---------|----------------|
| `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` | `.env` file (Reach) | DB, logs, frontend |
| `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY` | `.env` file (Reach) | DB, logs, frontend |
| `REACH_SERVICE_TOKEN` | `.env` file (public site) | DB, logs, frontend |
| `REACH_SIGNING_KEY` | `.env` file (public site) | DB, logs, frontend |

The `HmacSigner` class in Reach never includes raw secrets in any generated header except the `Authorization: Bearer` header (which contains the service token only, not the signing key).

---

## Payload Integrity

- The SHA-256 hash of the raw request body is computed by the sender and included as `X-Reach-Content-SHA256`.
- The public site independently computes `hash('sha256', $rawBody)` and compares using `hash_equals()` (constant-time comparison).
- A mismatch causes HTTP 401 `signature_error`.
- The same hash is also included in the HMAC canonical string, binding the body to the signature.

---

## Secret Redaction

### Reach (PHP)
- `SecretRedactor` strips Bearer tokens, signing keys, and API keys from all logged strings.
- Applied before any content reaches `AuditLogger::record()`.
- Never log raw payloads; log only safe metadata (content type, item ID, operation).

### Reach (Frontend)
- `maskSecrets.js` masks `Bearer` tokens and API key patterns in any frontend-displayed content.
- Environment variables are never sent to the frontend.
- The `ConnectionsPage` displays only `connection_key` and `health_status`, never raw credentials.

---

## TLS Requirement
- All communication between Reach and the public site API must be over HTTPS.
- No plaintext HTTP connections are permitted in production.
- The `AicountlyPublicSitePublisher` sets `verify_peer: true` in its HTTP client configuration.

---

## Audit Events

| Event | Trigger |
|-------|---------|
| `publishing.auth_failure` | HMAC verification failed |
| `publishing.replay_rejected` | Duplicate nonce or stale timestamp |
| `publishing.connection_health_checked` | Health check run |
| `publishing.connection_health_changed` | Health status transitions |
