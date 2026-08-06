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

## Public listing quality

`aicountly.com/blogs` renders the payload's `excerpt`, `featured_image_url` and
`reading_time_minutes` on each card, so a placeholder draft that reaches the
site looks exactly like a real post ("Untitled draft........"). Three
safeguards keep that off the listing:

1. **Readiness gate** — `BlogReadinessService` blocks any item whose body is a
   stub, is under `BLOG_MIN_PUBLISH_WORDS` (default 300), or is mostly
   punctuation. It warns when no cover image is assigned.
2. **Payload guard** — `BlogPublicationPayloadBuilder` re-runs the same check on
   the sanitised body at build time. A failure throws, the deployment is marked
   failed, and nothing is sent to the public site. The excerpt falls back
   through profile excerpt → version summary → meta description → derived
   excerpt, skipping any candidate that is not real prose.
3. **Audit command** — for content that is already live. The guards above are
   preventive: they stop the *next* bad publish, and do nothing about a post
   already on the site. Use this to clean those up:

   ```bash
   php spark reach:blog-listing-audit                     # report only
   php spark reach:blog-listing-audit --unpublish --fix   # take down + re-draft
   php spark reach:blog-dispatch --force
   php spark reach:work --queue blog,publishing --limit 40
   ```

   The report flags placeholder bodies, unusable excerpts, missing covers and
   missing alt text per content item. `--unpublish` takes placeholder articles
   off the public site immediately (only placeholder bodies — a real article
   with a missing cover is not pulled down). `--fix` re-queues a genuine draft.

   Each finding reports its `takedown` outcome:

   | Value | Meaning |
   |---|---|
   | `unpublished` | Removed from the public site |
   | `no_active_deployment` | Not published through Reach's connector — remove it on aicountly.com directly |
   | `unpublish_rejected_by_public_site` | The site refused; check connection health |
   | `unpublish_failed: …` | Local error, e.g. the deployment has no `public_content_id` |

   **The rewritten article does not republish by itself.** Redrafting resets the
   item to `outline_ready` with `approval_status = pending`, so it re-enters the
   normal human approval gate. That gate is deliberate and this command does not
   bypass it — after the worker generates the new draft, approve it in Reach and
   publish as usual.

### Cover images

Gallery covers are assigned by topical relevance (`CoverRelevanceScorer`)
against the article's category, portfolio stream, tags and title keywords —
not by rotation order alone, which is what previously fronted a GST article
with a landscape photo. Tuning:

| Variable | Default | Effect |
|---|---|---|
| `BLOG_REQUIRE_COVER_IMAGE` | `true` | No blog publishes without a suitable cover; a miss parks the article for review |
| `MEDIA_GALLERY_MIN_RELEVANCE` | `3` | Score floor an asset must clear to be assigned |
| `MEDIA_GALLERY_ALLOW_UNMATCHED_FALLBACK` | `false` | When true, falls back to any free cover instead of leaving the article without one |
| `MEDIA_GALLERY_REUSE_COOLDOWN_DAYS` | `30` | Minimum gap before a cover is reused |
| `BLOG_IMAGE_GENERATION_ENABLED` | `false` | Gates the **paid** AI image leg only — the gallery is assigned either way |
| `BLOG_ROUTINE_AI_COVER_FALLBACK` | `true` | Generate a cover when no gallery asset is relevant (needs the flag above) |
| `BLOG_MIN_PUBLISH_WORDS` | `300` | Minimum publishable article length |

Source order is gallery-first, generation-second. `BLOG_IMAGE_GENERATION_ENABLED`
used to short-circuit the whole cover step, so choosing "gallery, not paid API"
silently produced no cover at all; it now gates only the paid leg, and pipeline
items fall back to the gallery whenever that leg is off or unusable.

Untagged gallery assets can never be matched — Quality → Media Gallery flags
them and the deficit report counts them under `untagged`.

#### When no suitable cover exists

With `BLOG_REQUIRE_COVER_IMAGE=true` (the default) the article does **not**
walk on to publication:

1. A `cover_image` validation is recorded as **failed** on the content item,
   with the reason (`gallery_no_relevant_cover`, `image_generation_disabled`,
   or the provider error).
2. The item moves to `internal_review` — the Blog Command Centre review queue —
   and the SEO step is *not* chained, so automation stops there.
3. Readiness blocks every publish path twice over: gate 6 (unwaived failed
   validation) and gate 11b (featured image missing).

To clear it, either upload/tag a matching gallery asset and re-run the cover
work block, or — when a post genuinely should go out without a hero — waive the
`cover_image` validation with a reason (Daily Approval Centre →
`POST /v1/approval-queue/:id/waive-validation`). The waiver is audited and
downgrades gate 11b to a warning for that item only.

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
4. Verify aicountly.com `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` and `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY` match Reach's configuration.

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
