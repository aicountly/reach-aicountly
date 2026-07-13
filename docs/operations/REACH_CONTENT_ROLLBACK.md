# Reach Content Rollback Guide

## Overview

Content rollback unpublishes a content item from the public website without deleting it from either Reach or the public site database. This guide covers how to initiate, monitor, and recover from rollback operations.

---

## When to Roll Back

Use rollback when:

- A published article contains factual errors that need immediate removal.
- A compliance issue requires content to be taken down urgently.
- A technical issue caused incorrect content to be published.
- A legal or regulatory requirement mandates removal.

**Do not use rollback** to make editorial revisions — update the content and publish a new version instead.

---

## Rollback Process

### Via the Publishing UI

1. Navigate to **Publishing → Deployments**.
2. Locate the deployment for the content you want to unpublish.
3. Confirm the current status is `published` or `verified`.
4. Click **Rollback**.
5. Confirm the action in the confirmation dialog.
6. The deployment status changes to `rolled_back`.
7. A `publishing.rollback_initiated` audit event is recorded.

### What Happens Internally

1. `PublicationRollbackService` enqueues a rollback job.
2. The job calls `POST /api/reach/v1/content/{id}/unpublish` on the public site.
3. The public site sets `public_status = 'unpublished'` in `public_content_items`.
4. The public site excludes the item from `sitemap.xml`.
5. A `publishing.rollback_completed` audit event is recorded.

---

## Rollback Status

After rollback, the deployment record shows:

| Field | Value |
|-------|-------|
| `status` | `rolled_back` |
| `rolled_back_at` | Timestamp of rollback |
| `rollback_reason` | Operator-provided reason |

---

## Recovery After Rollback

After fixing the content:

1. Create and approve a new content version in Reach.
2. Run the readiness check.
3. Publish the corrected version.
4. The public site creates a new `public_content_items` record (or restores the existing one) with updated content.

**Note**: If the slug has not changed, the URL is restored to the same canonical URL. If the slug changed, the old URL will return 404 until a redirect is configured.

---

## Redirect Handling After Rollback

If content was rolled back and re-published with a different slug:

1. The old slug is recorded in `reach_content_redirects`.
2. Ensure the public site serves a 301 redirect from the old URL to the new URL.
3. Verify the redirect is working using Publishing → Verifications or a browser check.

---

## Audit Trail

All rollback events are recorded in the audit log:

| Event | Detail |
|-------|--------|
| `publishing.rollback_initiated` | Operator, content ID, deployment ID, reason |
| `publishing.rollback_completed` | Timestamp, public site response |
| `publishing.rollback_failed` | Error if public site call failed |

Use the audit log (via `AuditLogger`) to document the business justification for rollbacks.

---

## Emergency Rollback (CLI)

In a production emergency where the UI is unavailable, rollback can be triggered via a CodeIgniter command (if configured):

```bash
php spark publishing:rollback --deployment-id=123 --reason="Emergency removal"
```

This runs `PublicationRollbackService` directly from the CLI.

---

## Permissions Required

| Permission | Required For |
|-----------|-------------|
| `publishing.rollback` | Initiate rollback |
| `publishing.view` | View deployment records |

Rollback requires the `publishing.rollback` permission and cannot be delegated to roles below `content_manager`.
