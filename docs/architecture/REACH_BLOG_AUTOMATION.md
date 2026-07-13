# Reach Blog Automation Architecture

## Overview

The Blog Automation subsystem automates the end-to-end lifecycle of blog content from AI generation through to verified publication on the public website (`aicountly.com/blog/`). It enforces mandatory human approval before any content reaches the public, and integrates with the Phase 0–3 infrastructure for permissions, audit logging, and the job queue.

---

## Components

### BlogReadinessService

**Namespace**: `App\Services\Publishing\BlogReadinessService`

Evaluates whether a blog content item is ready for publication. Returns a structured readiness report containing:

- `status`: `ready`, `warning`, `blocked`, or `not_applicable`
- `blocking`: list of blocking issues (must be empty for publication)
- `warnings`: non-blocking advisory items
- `domain_check`, `seo_check`, `aeo_check`: sub-checks with individual statuses

**Required to pass before publication:**
- Content has an approved version (`approved` or `published` state in `reach_approvals`)
- SEO profile exists with `slug`, `meta_title`, `meta_description`, `primary_keyword`
- SEO status is not `blocked`
- AEO status is not `blocked`
- Structured data passes `StructuredDataValidator`

### BlogPublicationPayloadBuilder

**Namespace**: `App\Services\Publishing\BlogPublicationPayloadBuilder`

Builds the JSON envelope sent to the public site API. The envelope includes:

```json
{
  "reach_content_uuid": "...",
  "reach_content_id": 42,
  "reach_content_version_id": 100,
  "reach_content_version_number": 3,
  "content_type": "blog",
  "publication_target": "aicountly.com",
  "payload_checksum": "<sha256 of payload JSON>",
  "idempotency_key": "<uuid>",
  "payload": {
    "title": "...",
    "meta_title": "...",
    "meta_description": "...",
    "slug": "...",
    "body_html": "...",
    "reading_time_minutes": 5,
    "excerpt": "...",
    "robots_directive": "index,follow",
    "structured_data": [...],
    "author_name": "...",
    "published_at": "..."
  }
}
```

### BlogMetadataService

**Namespace**: `App\Services\Publishing\BlogMetadataService`

Derives blog-specific metadata:

- **`estimateReadingTime(string $html): int`** — Strips HTML tags, counts words, divides by 200 WPM (minimum 1 minute).
- **`deriveExcerpt(string $html, int $maxLength = 300): string`** — Extracts plain-text excerpt from HTML body, with ellipsis if truncated.

### BlogRefreshService

**Namespace**: `App\Services\Publishing\BlogRefreshService`

Handles content refresh: when an approved update is available for a previously published item, schedules a `PublicationJob` with `operation = 'update'` to push the new version.

---

## Data Flow

```
Human approves content
        ↓
BlogReadinessService.check(contentId) → ready?
        ↓
BlogPublicationPayloadBuilder.build(contentId, versionId)
        ↓
PublicationJob enqueued (reach_jobs table)
        ↓
AicountlyPublicSitePublisher.createDraft() → POST /api/reach/v1/content/drafts
        ↓
AicountlyPublicSitePublisher.publish() → POST /api/reach/v1/content/{id}/publish
        ↓
PublicationVerificationJob → GET /api/reach/v1/content/{id}/verification
```

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `reach_blog_seo_profiles` | Per-content SEO metadata for blog posts |
| `reach_blog_publication_profiles` | Reading time, excerpt, author, featured image |
| `reach_content_deployments` | Records each publication attempt and outcome |
| `reach_publication_verifications` | Stores verification results |
| `reach_content_redirects` | Slug-change redirects for published posts |

---

## Security

- All publication API calls are HMAC-SHA256 signed (see `REACH_PUBLICATION_SECURITY.md`).
- The payload checksum (`SHA-256` of the JSON payload string) is included in both the request and the envelope, and verified by the public site.
- Body HTML is sanitized by `HtmlSanitizer` on the public-site side.
- Service credentials are never logged, cached in the database, or sent to the frontend.

---

## Phase Constraints

- **No autonomous publication**: Human approval is mandatory.
- **No direct DB writes**: All content flows through the publishing API.
- **No community publishing**: This subsystem handles blog only.
- **Reuses Phase 0–3 infrastructure**: Job queue, RBAC, audit logging, approval framework.
