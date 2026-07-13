# Reach Publication Lifecycle Architecture

## Overview

This document describes the end-to-end lifecycle of a content item from editorial approval to verified publication on the public website.

---

## Lifecycle States

```
[content draft]
      ↓
[human approval] ← mandatory, blocks all publication paths
      ↓
[readiness check] ← SEO, AEO, structured data, domain checks
      ↓
[publication queued] ← PublicationJob enqueued in reach_jobs
      ↓
[draft created on public site] ← createDraft API call
      ↓
[published on public site] ← publish API call
      ↓
[verification pending] ← PublicationVerificationJob enqueued
      ↓
[verified] ← canonical URL resolves, metadata matches
```

---

## Deployment States

`reach_content_deployments.status` transitions:

| Status | Description |
|--------|-------------|
| `queued` | Job is in `reach_jobs` queue, not yet started |
| `sending` | Job is running; API call in progress |
| `accepted` | Public site returned 2xx for draft creation |
| `published` | Public site confirmed publication |
| `verified` | Verification job confirmed live URL |
| `failed` | Non-retryable error; requires manual intervention |
| `cancelled` | Cancelled before processing |
| `rolled_back` | Content unpublished via rollback |

---

## Job Types

### PublicationJob

Handles the `createDraft → publish` flow for new content.

**Parameters:**
- `content_item_id`
- `version_id`
- `operation`: `publish`, `update`, `unpublish`
- `connection_key`
- `idempotency_key` (UUID, unique per attempt)

**Retry policy:** Up to 5 attempts with exponential backoff (base 30s, max 3600s). Only retryable error categories trigger a retry.

### PublicationVerificationJob

Runs after publication to confirm the content is live.

**Checks:**
- HTTP GET to canonical URL returns 2xx
- Title matches expected value
- `robots_directive` matches publication payload
- `sitemap_status` is `included` (unless explicitly excluded)

### PublicationReconciliationJob

Periodic reconciliation that finds deployments in `published` state without a corresponding verification result, and enqueues new verification jobs.

---

## Retry Policy

`PublishingRetryService` determines whether an error is retryable based on `PublishingErrorClassifier`:

| Error Category | Retryable |
|----------------|-----------|
| `server_error` (5xx) | Yes |
| `timeout` | Yes |
| `network_error` | Yes |
| `rate_limited` | Yes (after backoff) |
| `auth_error` | No |
| `validation_error` | No |
| `not_found` | No |
| `unknown_error` | No |

---

## Rollback

`PublicationRollbackService` handles rollback:

1. Calls `POST /api/reach/v1/content/{id}/unpublish` on the public site.
2. Updates `reach_content_deployments.status` to `rolled_back`.
3. Logs `publishing.rollback_initiated` and `publishing.rollback_completed` audit events.
4. Does not delete content records; marks them as inactive.

---

## Idempotency

Every publication attempt has a unique `idempotency_key` (UUID). The public site checks this key and returns the existing result without re-processing if the same key is received again. This prevents duplicate publications during retries.

---

## Audit Events

| Event | Trigger |
|-------|---------|
| `publishing.queued` | Job enqueued |
| `publishing.draft_created` | Draft successfully created on public site |
| `publishing.published` | Content published on public site |
| `publishing.failed` | Publication attempt failed |
| `publishing.verification_started` | Verification job started |
| `publishing.verified` | Content verified live |
| `publishing.verification_failed` | Verification failed |
| `publishing.rollback_initiated` | Rollback triggered |
| `publishing.rollback_completed` | Rollback confirmed |
