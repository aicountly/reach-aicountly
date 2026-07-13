# Reach Publication Verification Architecture

## Overview

After content is published on the public website, Reach runs a verification job to confirm the content is actually live and accessible. Verification results are stored in `reach_publication_verifications` and surfaced in the Publishing section frontend.

---

## Components

### PublicationVerificationJob

Enqueued immediately after a successful publication. Performs:

1. HTTP GET request to the canonical URL of the published content.
2. Records HTTP status code, response time, and error (if any).
3. Updates the deployment record with `verified` or `failed` status.
4. Logs audit events.

### PublicationReconciliationJob

Runs on a schedule (e.g., every hour) to find deployments that were published but never verified, and enqueues new verification jobs for them.

### SitemapVerificationService

**Namespace**: `App\Services\Publishing\SitemapVerificationService`

Checks whether a published content item is present in the public site sitemap:

- Fetches `sitemap.xml` or `sitemap-blog.xml` / `sitemap-kb.xml`
- Searches for the canonical URL
- Returns `included` or `excluded`

---

## Verification Data Model

### `reach_publication_verifications`

| Column | Type | Description |
|--------|------|-------------|
| `id` | `BIGSERIAL` | Primary key |
| `deployment_id` | `BIGINT` | FK to `reach_content_deployments` |
| `status` | `TEXT` | `pending`, `verified`, `failed`, `skipped` |
| `http_status_code` | `INTEGER` | HTTP response code |
| `response_time_ms` | `INTEGER` | Response time in milliseconds |
| `error_message` | `TEXT` | Safe error description (no secrets) |
| `canonical_url` | `TEXT` | URL that was checked |
| `checked_at` | `TIMESTAMPTZ` | When the check was performed |
| `created_at` | `TIMESTAMPTZ` | Record creation time |

---

## Verification States

| Status | Meaning |
|--------|---------|
| `pending` | Scheduled but not yet run |
| `verified` | URL returned 2xx, content confirmed live |
| `failed` | URL returned error or content metadata mismatch |
| `skipped` | Skipped (e.g., `noindex` content) |

---

## Failure Handling

If verification fails:

1. `reach_publication_verifications.status` is set to `failed`.
2. The deployment's `verification_status` column is updated.
3. A `publishing.verification_failed` audit event is recorded.
4. The UI surfaces the failure in the Verifications list page.
5. Operations can manually re-trigger verification via the frontend or re-queue a `PublicationVerificationJob`.

---

## Audit Events

| Event | Trigger |
|-------|---------|
| `publishing.verification_started` | Job begins checking the URL |
| `publishing.verified` | URL confirmed live with correct status |
| `publishing.verification_failed` | URL unreachable or returned non-2xx |
| `publishing.sitemap_verified` | URL found in sitemap |
| `publishing.sitemap_missing` | URL not found in sitemap |

---

## Security Notes

- Verification only reads the public URL; it does not write anything.
- No service credentials are used for verification (public URL check only).
- Error messages stored in `reach_publication_verifications.error_message` are the safe message from `PublishingErrorClassifier`, never raw exception messages.
