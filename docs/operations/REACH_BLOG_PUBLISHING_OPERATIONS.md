# Reach Blog Publishing Operations Guide

## Overview

This guide covers day-to-day operations for blog content publication, including readiness checks, publication triggering, monitoring deployments, and handling failures.

---

## Pre-Publication Checklist

Before triggering publication for a blog post:

1. **Approve the content** — Navigate to Approvals and verify the content version has `approved` status. Publication is blocked without approval.
2. **Run readiness check** — Go to Publishing → Readiness, enter the content ID, click "Check Readiness". All blockers must be resolved.
3. **Verify SEO profile** — Go to Publishing → SEO Editor for the content. Ensure:
   - `slug` is set (lowercase, hyphens only)
   - `meta_title` ≤ 70 characters
   - `meta_description` ≤ 165 characters
   - `primary_keyword` is set
   - `canonical_preference` is correct
4. **Check connections** — Go to Publishing → Connections. The active connection must show `healthy` status.

---

## Triggering Publication

Publication is triggered from the Blog Publishing list page:

1. Navigate to Publishing → Blogs.
2. Find the content item and click "Publish".
3. A `PublicationJob` is enqueued in `reach_jobs`.
4. The deployment status changes to `queued` → `sending` → `accepted` → `published`.
5. A verification job runs automatically after publication.

---

## Monitoring Deployments

### Deployment List

Navigate to Publishing → Deployments to view all deployment records. Filter by:
- Status: `queued`, `sending`, `published`, `verified`, `failed`, `rolled_back`
- Content type: blog

### Verification List

Navigate to Publishing → Verifications to view verification results. A `verified` result means the canonical URL is live and accessible.

### Alert Conditions

Monitor for:
- Deployments stuck in `sending` for more than 10 minutes
- Deployments in `failed` status
- Verifications in `failed` status
- Connections with `unhealthy` or `degraded` health status

---

## Handling Failures

### Retryable Failures

If a deployment shows `failed` with a retryable error (server_error, timeout, network_error), the retry service automatically re-queues the job with exponential backoff. No manual intervention needed for up to 5 attempts.

### Non-Retryable Failures

If the deployment fails with a non-retryable error (auth_error, validation_error), manual investigation is required:

1. Check `reach_content_deployments.error_category` for the error type.
2. For `auth_error`: verify `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` and `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY` are correct.
3. For `validation_error`: check the content payload for missing required fields.
4. Re-queue manually after fixing the root cause.

### Connection Health Failure

If the connection shows `unhealthy`:

1. Navigate to Publishing → Connections.
2. Click "Check Health" to run an immediate health check.
3. Review `last_health_error` for the failure reason.
4. Verify the public site `REACH_SERVICE_TOKEN` and `REACH_SIGNING_KEY` environment variables match Reach's configuration.

---

## Rollback

To unpublish a blog post:

1. Navigate to Publishing → Deployments.
2. Find the deployment record for the published content.
3. Click "Rollback".
4. Confirm the action. A rollback job is enqueued immediately.
5. The deployment status changes to `rolled_back`.
6. The public site sets the content to `unpublished`.

**Note:** Rollback does not delete content from the public site database; it marks it as unpublished and excludes it from the sitemap.

---

## Slug Changes

If a blog post's slug is changed after publication:

1. The `CanonicalUrlPolicy` detects the slug change and marks `requiresRedirect = true`.
2. A redirect record is created in `reach_content_redirects` (old slug → new slug).
3. The new canonical URL is used for the update deployment.
4. The public site (if configured) should serve a 301 redirect from the old URL.
