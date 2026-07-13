# Reach SEO/AEO Engine Architecture

## Overview

The SEO/AEO Engine evaluates and manages search engine optimisation (SEO) and answer engine optimisation (AEO) metadata for all content scheduled for publication. It ensures content meets quality thresholds before publication and exposes actionable feedback to editors.

---

## SEO Subsystem

### SeoReadinessService

**Namespace**: `App\Services\Publishing\SeoReadinessService`

Evaluates a content item's SEO profile and returns a readiness status:

- **`ready`**: All required fields present and within acceptable ranges.
- **`warning`**: Non-blocking issues found (e.g., meta description slightly over limit).
- **`blocked`**: Critical issues that prevent publication (e.g., missing `slug` or `primary_keyword`).

**Checks performed:**

| Check | Blocked if | Warning if |
|-------|-----------|-----------|
| `slug` | Missing | — |
| `meta_title` | Missing | >70 characters |
| `meta_description` | Missing | >165 characters |
| `primary_keyword` | Missing | — |
| `robots_directive` | Invalid value | — |
| `focus_language` | Missing | — |

### SeoProfileController

**Route**: `GET/PUT /api/v1/publishing/seo/{contentId}`

Allows editors to view and update the SEO profile for a content item. Supports the following fields:

- `primary_keyword`, `secondary_keywords` (array)
- `meta_title`, `meta_description`
- `slug` (lowercase, hyphens only)
- `canonical_preference` (`self_canonical`, `canonical_to_existing`, `noindex`, `redirect_to_existing`, `historical_archive`)
- `robots_directive` (`index,follow`, `noindex,follow`, `index,nofollow`, `noindex,nofollow`)
- `focus_language`

### CanonicalUrlPolicy

**Namespace**: `App\Services\Publishing\CanonicalUrlPolicy`

Determines the canonical URL strategy for content:

- **`self_canonical`**: Content at its own slug is canonical.
- **`canonical_to_existing`**: Points to an existing URL (requires `canonical_target_url`).
- **`noindex`**: Content is published but hidden from search engines.
- **`redirect_to_existing`**: Sends HTTP 301 to another URL.
- **`historical_archive`**: Old content preserved but de-emphasised.

Detects slug changes that require HTTP 301 redirects, and validates slug format (`^[a-z0-9]+(-[a-z0-9]+)*$`).

---

## AEO Subsystem

### AeoReadinessService

**Namespace**: `App\Services\Publishing\AeoReadinessService`

Evaluates content for answer engine readiness (used by AI search tools like SGE, Perplexity, etc.):

- Checks for `answer_summary` (natural-language answer to the primary question)
- Validates `faq_pairs` if content type has FAQs
- Validates `primary_question` (question the content answers)
- Verifies at least one structured data type is valid for AEO contexts (`FAQPage`, `HowTo`)

---

## Publication Readiness Aggregation

### PublicationReadinessService

**Namespace**: `App\Services\Publishing\PublicationReadinessService`

Aggregates all readiness checks into a single response. Used by the Readiness page in the Publishing section.

```json
{
  "status": "blocked",
  "content_type": "blog",
  "blocking": ["SEO profile missing slug", "No approved version"],
  "warnings": ["Meta description too long"],
  "domain_check": { "status": "ready" },
  "seo_check": { "status": "blocked" },
  "aeo_check": { "status": "warning" }
}
```

---

## Permissions

| Permission | Description |
|-----------|-------------|
| `seo.view` | View SEO profiles |
| `seo.manage` | Create/update SEO profiles |
| `seo.evaluate` | Run SEO evaluation |
| `aeo.view` | View AEO profiles |
| `aeo.manage` | Manage AEO profiles |
| `aeo.evaluate` | Run AEO evaluation |

---

## Audit Events

| Event | Trigger |
|-------|---------|
| `seo.profile_created` | New SEO profile saved |
| `seo.profile_updated` | SEO profile fields changed |
| `seo.evaluation_run` | Evaluation triggered by editor |
| `seo.status_changed` | Status transitions (e.g., `warning` → `ready`) |
| `aeo.profile_created` | New AEO profile saved |
| `aeo.evaluation_run` | AEO evaluation triggered |
| `aeo.status_changed` | AEO status transitions |
