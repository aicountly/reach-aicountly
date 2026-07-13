# Reach Community Security Model — Phase 5

## Overview

This document covers the threat model, attack surface, and mitigations for the Phase 5 community Q&A subsystem.

---

## Attack Surface

### 1. Inbound Community Question Content

Community questions are untrusted external content. They may contain:
- Prompt injection attempts targeting the AI generation pipeline
- Stored XSS payloads targeting the Reach operator UI
- Personal data that must not be retained without consent
- Spam or abusive content

**Mitigations:**
- All question body content is escaped on render in the Reach frontend.
- AI generation requests always place community question content in a clearly delimited `<question>` block with explicit instructions that question content is untrusted.
- `OfficialAnswerModerationService` runs a `prompt_injection` detector on community questions before they reach the generation pipeline.
- `personal_data_detected` flag is set by classification and blocks progression until reviewed.

### 2. Official Answer Content (Reach-generated)

AI-generated content may contain:
- Hallucinated product features
- Unsupported compliance assertions
- Links to unsafe external URLs
- Malicious HTML embedded in AI output

**Mitigations:**
- `OfficialAnswerModerationService` scans all AI-generated HTML before storage.
- URL policy validation (allowlist of safe external domains) applied to all links in AI-generated content.
- `HtmlSanitizer` applied before publication envelope is built.
- `OfficialAnswerValidationService` checks product fact grounding for all feature claims.

### 3. HMAC Publishing API

The cross-repository HMAC API is the primary attack surface for the publishing pipeline.

**Threat vectors:**
- Replay attacks using captured requests
- HMAC bypass via weak key material
- Nonce reuse enabling request replay
- Payload tampering after signing
- Oversized payloads causing denial of service
- Race conditions in idempotency table

**Mitigations:**
- Nonce uniqueness enforced by `reach_api_nonces` table with 60-second TTL.
- Timestamp tolerance ±60 seconds rejects stale or future-dated requests.
- `X-Reach-Content-SHA256` header verified against actual request body.
- HMAC key material must be ≥32 bytes.
- Maximum body size enforced by `COMMUNITY_ANSWER_MAX_BODY_BYTES` (default 64 KB).
- Idempotency key stored atomically; concurrent duplicates return cached response.

### 4. Public Site XSS

Official answer HTML is rendered on the public community page.

**Mitigations:**
- `HtmlSanitizer` runs on the public site before storage.
- Allowed element list: `p, strong, em, ul, ol, li, blockquote, code, pre, a, br, h2, h3, h4`.
- Links are protocol-validated: only `http://`, `https://`, and `mailto:` are allowed.
- JavaScript URL schemes (`javascript:`, `data:`, `vbscript:`) are rejected.
- Event attributes (`onclick`, `onload`, etc.) are stripped.
- Embedded `<script>`, `<object>`, `<iframe>`, `<form>`, `<svg>` tags are stripped.

### 5. Self-Approval and Privilege Escalation

**Threats:**
- A content editor approves their own AI-generated answer.
- A standard user escalates to approval permission.
- Approval state is bypassed by direct DB manipulation through API.

**Mitigations:**
- `OfficialAnswerApprovalService` checks that the approving user is not the same as the last editor for high-risk answers.
- Approval endpoints require `community.answer.approve` permission (separate from `community.answer.edit`).
- Approval is only accepted when the version checksum in the request matches the stored version checksum.
- All state transitions are validated against the allowed transition map before execution.

### 6. Fake Engagement Ingestion

**Threats:**
- Synthetic engagement events submitted to inflate analytics.
- Bot-generated page views recorded as genuine.

**Mitigations:**
- `CommunityEngagementIngestionService` applies bot-filter validation (user-agent, rate limits) before recording events.
- Each event requires a `deduplication_key`; duplicate events are discarded.
- `validated = false` for events that failed bot filtering; analytics queries only count validated events.
- No engagement counts are stored directly on the `community_answers` table (no `helpful_count` for official answers managed by Reach).

### 7. SQL Injection

**Mitigations:**
- All database queries use CodeIgniter 4's parameterised query builder or named parameter binding.
- Raw SQL in migrations only; no user-controlled data in raw SQL.
- Public site uses PDO prepared statements for all queries.

### 8. Path Traversal and SSRF

**Threats:**
- Source URL field in community questions points to internal services.
- AI-generated source references point to internal URLs.

**Mitigations:**
- Source URLs are validated against an allowlist of public domain patterns.
- Outbound HTTP calls from Reach (e.g., source fetching) use a safe HTTP client that rejects private IP ranges and localhost.

### 9. Secret Leakage

**Threats:**
- HMAC signing keys logged in audit events.
- Service tokens returned in API responses.
- AI provider keys exposed in generation artifacts.

**Mitigations:**
- `SecretRedactor` middleware strips known secret patterns from all audit log payloads.
- Error responses for authentication failures use generic messages only (no key material).
- Generation artifacts store only safe metadata; raw provider responses are scrubbed before storage.
- `.env` files are in `.gitignore`; `.env.example` contains only placeholder values.

### 10. Rate Limiting

**Mitigations:**
- Community API endpoints in Reach are behind the `throttle` filter with per-user limits.
- The public community publishing API does not have a global rate limit (server-to-server), but timestamp tolerance and nonce validation prevent replay bursts.
- Community question intake endpoints have per-user throttle limits to prevent spam intake.

---

## Security Controls Summary

| Control | Implementation |
|---------|---------------|
| Authentication | JWT (Reach API), HMAC service-to-service (publishing) |
| Authorisation | PermissionFilter with 22 community.* granular permissions |
| Input validation | CI4 validators + custom domain validators |
| Output encoding | React auto-escaping (frontend), htmlspecialchars (PHP) |
| HTML sanitisation | HtmlSanitizer (both repos) |
| URL validation | UrlPolicy allowlist |
| SQL injection prevention | Parameterised queries (CI4 + PDO) |
| CSRF | N/A (API with JWT; no cookie-based forms in admin) |
| HMAC signing | HmacSigner, SHA-256, 32+ byte keys |
| Nonce replay protection | reach_api_nonces table, 60s tolerance |
| Payload integrity | X-Reach-Content-SHA256 body hash |
| Prompt injection detection | OfficialAnswerModerationService |
| Secret masking | SecretRedactor in audit pipeline |
| Oversized payload | COMMUNITY_ANSWER_MAX_BODY_BYTES enforcement |
| Fake engagement prevention | deduplication_key + bot filter |
| Grounding enforcement | Source coverage validation before approval |
| Self-approval prevention | OfficialAnswerApprovalService editor-approver check |
| Checksum locking | Version checksum verified before publication |
