# Reach Community Publishing Contract — Phase 5

## Overview

Phase 5 extends the Phase 4 HMAC publishing protocol to cover community Q&A content. All community publishing operations use the same authentication, signing, replay protection, and idempotency infrastructure as Phase 4 blog/KB publishing.

---

## Base URL and Versioning

```
/api/reach/v1/community/
```

API version: `1` (same as Phase 4; `X-Reach-API-Version: 1`)

---

## Authentication

Identical to Phase 4. See `REACH_PUBLIC_PUBLISHING_API_CONTRACT.md` for full details.

### Required Headers

| Header | Description |
|--------|-------------|
| `Authorization` | `Bearer <REACH_SERVICE_TOKEN>` |
| `X-Reach-Key-Id` | Key identifier (e.g., `reach-v1`) |
| `X-Reach-Timestamp` | Unix timestamp (integer, ±60 seconds) |
| `X-Reach-Nonce` | UUID v4, unique per request |
| `X-Reach-Signature` | HMAC-SHA256 of canonical string |
| `X-Reach-Content-SHA256` | SHA-256 of request body |
| `X-Idempotency-Key` | UUID v4, unique per operation attempt |
| `X-Reach-API-Version` | `1` |
| `X-Request-Id` | Correlation ID for tracing |
| `Content-Type` | `application/json` |

### HMAC Canonical String

```
METHOD\n
path\n
timestamp\n
nonce\n
content-sha256\n
idempotency-key\n
api-version
```

---

## Endpoints

### POST /reach/v1/community/questions

Create an official question record on the public site. Used when Reach publishes an official FAQ-style question.

**Request body:**
```json
{
  "reach_answer_uuid": "uuid",
  "question_uuid": "uuid",
  "operation": "create_question",
  "content_type": "community_question",
  "idempotency_key": "uuid",
  "payload_checksum": "sha256hex",
  "payload": {
    "title": "string",
    "body": "string",
    "category_slug": "string",
    "tags": ["string"],
    "slug": "string",
    "source_type": "official_question",
    "robots_directive": "index,follow"
  }
}
```

**Response 201:**
```json
{
  "success": true,
  "operation": "create_question",
  "public_question_id": 42,
  "public_question_slug": "string",
  "request_id": "string",
  "idempotent_replay": false
}
```

---

### POST /reach/v1/community/answers

Create an official answer record (draft).

**Request body:**
```json
{
  "reach_answer_uuid": "uuid",
  "question_uuid": "uuid",
  "operation": "create_answer",
  "content_type": "community_answer",
  "reach_content_version_number": 1,
  "official_identity_slug": "aicountly-support",
  "idempotency_key": "uuid",
  "payload_checksum": "sha256hex",
  "payload": {
    "body": "html string",
    "excerpt": "plain text",
    "ai_assisted": true,
    "human_reviewed": true,
    "approved_at": "ISO8601",
    "answer_version": 1,
    "robots_directive": "index,follow",
    "structured_data": []
  }
}
```

**Response 201:**
```json
{
  "success": true,
  "operation": "create_answer",
  "public_answer_id": 99,
  "public_answer_uuid": "uuid",
  "public_status": "draft",
  "request_id": "string",
  "idempotent_replay": false
}
```

---

### PUT /reach/v1/community/answers/{uuid}

Update an existing answer (creates new version on public site).

**Response 200:** Same structure as create with `operation: "update_answer"`.

---

### POST /reach/v1/community/answers/{uuid}/publish

Publish an answer. Only applies to approved, checksummed versions.

**Request body:**
```json
{
  "reach_answer_uuid": "uuid",
  "idempotency_key": "uuid",
  "payload_checksum": "sha256hex",
  "payload": {
    "canonical_url": "https://aicountly.com/community/question/slug#official-answer",
    "sitemap_eligible": true,
    "robots_directive": "index,follow"
  }
}
```

**Response 200:**
```json
{
  "success": true,
  "operation": "publish",
  "public_answer_id": 99,
  "public_status": "published",
  "canonical_url": "https://aicountly.com/...",
  "public_version": 1,
  "published_at": "ISO8601",
  "sitemap_status": "included",
  "request_id": "string"
}
```

---

### POST /reach/v1/community/answers/{uuid}/unpublish

Unpublish a published answer.

**Response 200:**
```json
{
  "success": true,
  "operation": "unpublish",
  "public_answer_id": 99,
  "public_status": "unpublished",
  "request_id": "string"
}
```

---

### POST /reach/v1/community/answers/{uuid}/withdraw

Permanently withdraw an answer. Preserves record but removes from public rendering and sitemap.

**Request body:**
```json
{
  "reach_answer_uuid": "uuid",
  "idempotency_key": "uuid",
  "payload_checksum": "sha256hex",
  "payload": {
    "reason": "string"
  }
}
```

**Response 200:**
```json
{
  "success": true,
  "operation": "withdraw",
  "public_answer_id": 99,
  "public_status": "withdrawn",
  "withdrawn_at": "ISO8601",
  "request_id": "string"
}
```

---

### POST /reach/v1/community/answers/{uuid}/restore

Restore a withdrawn or unpublished answer to published state.

**Response 200:**
```json
{
  "success": true,
  "operation": "restore",
  "public_answer_id": 99,
  "public_status": "published",
  "request_id": "string"
}
```

---

### GET /reach/v1/community/answers/{uuid}/status

Retrieve current publication status. No HMAC required (GET with bearer token only).

**Response 200:**
```json
{
  "success": true,
  "public_answer_id": 99,
  "public_status": "published",
  "canonical_url": "string",
  "public_version": 1,
  "payload_checksum": "sha256hex",
  "ai_assisted": true,
  "human_reviewed": true,
  "correction_note": null,
  "withdrawn_at": null,
  "updated_at": "ISO8601",
  "request_id": "string"
}
```

---

### GET /reach/v1/community/answers/{uuid}/verification

Retrieve verification data for reconciliation.

**Response 200:**
```json
{
  "success": true,
  "operation": "verify",
  "public_answer_id": 99,
  "public_status": "published",
  "canonical_url": "string",
  "public_version": 1,
  "payload_checksum": "sha256hex",
  "sitemap_status": "included",
  "robots_directive": "index,follow",
  "updated_at": "ISO8601",
  "request_id": "string"
}
```

---

## Error Response Format

All errors follow the Phase 4 pattern:

```json
{
  "success": false,
  "error_code": "string",
  "safe_error_message": "string"
}
```

| Code | HTTP | Description |
|------|------|-------------|
| `authentication_error` | 401 | Bearer token invalid |
| `signature_error` | 401 | HMAC signature mismatch or body hash mismatch |
| `replay_rejected` | 401 | Nonce reused or timestamp out of tolerance |
| `unsupported_version` | 400 | API version not supported |
| `validation_error` | 422 | Missing required fields |
| `not_found` | 404 | Answer or question not found |
| `conflict` | 409 | Checksum mismatch on publish |
| `payload_too_large` | 413 | Body exceeds `COMMUNITY_ANSWER_MAX_BODY_BYTES` |
| `configuration_error` | 500 | Public site not configured for community publishing |

---

## Idempotency

The public site checks `reach_answer_uuid` for create operations and returns the existing record if found. For update/publish/unpublish operations, the `idempotency_key` is stored in `reach_api_community_idempotency` and duplicate requests return the cached response within the TTL window.

---

## Environment Variables

### Reach (.env.example additions)

```
# Phase 5 — Community publishing uses same AICOUNTLY_PUBLIC_SITE_* vars as Phase 4
COMMUNITY_OFFICIAL_IDENTITY_DEFAULT=aicountly-official
COMMUNITY_ANSWER_MAX_BODY_BYTES=65536
COMMUNITY_RISK_HIGH_REQUIRES_PROFESSIONAL_REVIEW=true
REACH_PUB_COMMUNITY_MOCK=
```

### aicountly-com (.env.example additions — no new secrets)

Community publishing uses the existing `REACH_SERVICE_TOKEN` and `REACH_SIGNING_KEY`. The same nonce store and `ReachAuth` middleware cover both blog/KB and community endpoints.
