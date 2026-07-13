# Reach Knowledge Base Automation Architecture

## Overview

The Knowledge Base (KB) Automation subsystem automates the lifecycle of knowledge-base articles from AI generation through to verified publication on `aicountly.com/help/`. KB articles have a richer structure than blog posts: they are typed (`how_to`, `concept`, `reference`, `troubleshooting`, `tutorial`, `faq`, `release_note`, `integration`, `api_reference`, `glossary`), have structured steps, and track version applicability.

---

## Article Types

| Type | Description |
|------|-------------|
| `how_to` | Step-by-step procedural guide |
| `concept` | Explanation of a concept or feature |
| `reference` | Reference documentation |
| `troubleshooting` | Problem/solution structure |
| `tutorial` | Extended hands-on walkthrough |
| `faq` | Frequently asked questions |
| `release_note` | Feature or fix release notes |
| `integration` | Third-party integration guide |
| `api_reference` | API documentation |
| `glossary` | Term definitions |

---

## Components

### KBReadinessService

**Namespace**: `App\Services\Publishing\KBReadinessService`

Checks that a KB article is ready for publication:

- Content has an approved version
- KB profile exists with `article_type` and `slug`
- Structure is valid (`KnowledgeBaseStructureValidator` passes)
- SEO profile exists and is not blocked
- Version applicability is configured (`KBVersionApplicabilityService`)

### KnowledgeBaseStructureValidator

**Namespace**: `App\Services\Publishing\KnowledgeBaseStructureValidator`

Validates the structured steps attached to a KB article:

- Steps must be sequential (no gaps, no duplicates)
- Each step must have `step_number`, `title`, and `description`
- No unsafe instructions (patterns: `delete all`, `rm -rf`, `DROP TABLE`, `TRUNCATE`, `shutdown`, password patterns)
- Version applicability must be consistent

Validation is strict: any step validation failure blocks publication.

### KBPublicationPayloadBuilder

**Namespace**: `App\Services\Publishing\KBPublicationPayloadBuilder`

Constructs the publication envelope for knowledge-base articles. Extends the base payload with:

```json
{
  "content_type": "knowledge_base",
  "payload": {
    "article_type": "how_to",
    "version_applicability": {
      "type": "all_current_versions"
    },
    "steps": [
      {
        "step_number": 1,
        "title": "Open GSTR-3B",
        "description": "Navigate to the GST portal..."
      }
    ]
  }
}
```

### KBVersionApplicabilityService

**Namespace**: `App\Services\Publishing\KBVersionApplicabilityService`

Manages which product versions an article applies to:

- `all_current_versions`: applies to all active versions
- `specific_versions`: applies to a list of named versions
- `version_range`: applies from version `from` to version `to`
- `planned_version`: applies to an upcoming version (requires `preview_label`)
- `historical_version`: applies to an archived version
- `not_applicable`: not version-specific (e.g., conceptual content)

---

## Data Flow

```
Human approves KB content
        ↓
KBReadinessService.check(contentId)
        ↓
KnowledgeBaseStructureValidator.validate(steps)
        ↓
KBPublicationPayloadBuilder.build(contentId, versionId)
        ↓
PublicationJob enqueued
        ↓
AicountlyPublicSitePublisher.createDraft() → POST /api/reach/v1/content/drafts
        ↓
AicountlyPublicSitePublisher.publish() → POST /api/reach/v1/content/{id}/publish
        ↓
PublicationVerificationJob verifies canonical URL
```

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `reach_kb_publication_profiles` | Article type, difficulty, estimated time |
| `reach_kb_steps` | Structured steps for how-to/tutorial articles |
| `reach_kb_version_applicability` | Version applicability configuration |
| `reach_content_deployments` | Shared deployment records |
| `reach_publication_verifications` | Verification results |

---

## Version Applicability Validation Rules

| Type | Required Fields | Optional |
|------|----------------|---------|
| `all_current_versions` | (none) | — |
| `specific_versions` | `versions` (non-empty array) | — |
| `version_range` | `from` | `to` |
| `planned_version` | — | `preview_label` |
| `historical_version` | (none) | — |
| `not_applicable` | (none) | — |

---

## Constraints

- Unsafe instructions are blocked at validation time, before the job is enqueued.
- Steps with missing titles or descriptions are blocked.
- No autonomous publication: human approval required.
- Reuses Phase 0–3 job queue, RBAC, and audit infrastructure.
