# Reach Knowledge Base Operations Guide

## Overview

This guide covers day-to-day operations for knowledge base article publication, including article structure validation, version applicability management, and monitoring.

---

## KB Article Types

Operators should be familiar with the article types and their publishing requirements:

| Type | Steps Required | Version Applicability |
|------|---------------|----------------------|
| `how_to` | Yes | Recommended |
| `tutorial` | Yes | Recommended |
| `concept` | No | Optional |
| `reference` | No | Optional |
| `troubleshooting` | Recommended | Optional |
| `faq` | No (FAQ pairs) | Optional |
| `release_note` | No | Mandatory |
| `integration` | Recommended | Recommended |
| `api_reference` | No | Mandatory |
| `glossary` | No | No |

---

## Pre-Publication Checklist

1. **Approve the content** — KB articles require human approval just like blog posts.
2. **Validate structure** — The structure is auto-validated when running a readiness check. For `how_to` / `tutorial` articles, ensure:
   - All steps have `step_number`, `title`, and `description`
   - Step numbers are sequential with no gaps or duplicates
   - No unsafe instructions (shell commands like `rm -rf`, SQL like `DROP TABLE`)
3. **Set version applicability** — For version-specific articles (`release_note`, `api_reference`), ensure the version applicability type is set.
4. **Run readiness check** — Publishing → Readiness → Check.

---

## Structure Validation

KB structure validation runs automatically during the readiness check. Common failures:

| Issue | Resolution |
|-------|-----------|
| Non-sequential step numbers | Renumber steps 1, 2, 3, ... |
| Missing step title | Add descriptive title to each step |
| Missing step description | Add description to each step |
| Unsafe instruction detected | Review and rephrase the instruction |
| Duplicate step number | Remove duplicate or renumber |

---

## Version Applicability Configuration

Navigate to the KB profile for the content item to set version applicability:

| Type | When to Use |
|------|------------|
| `all_current_versions` | Article applies to all active product versions |
| `specific_versions` | Provide list of version strings, e.g., `["2.0", "2.1"]` |
| `version_range` | Set `from` and optionally `to` (open-ended if `to` omitted) |
| `planned_version` | Set `preview_label` for upcoming version |
| `historical_version` | Legacy content for old version |
| `not_applicable` | Conceptual content not version-specific |

---

## Monitoring KB Deployments

KB deployments appear in Publishing → Deployments (filter by content type `knowledge_base`) and Publishing → Knowledge Base.

### Common Monitoring Checks

- Deployments for `how_to` and `tutorial` articles should show `verified` within ~5 minutes of publication.
- `release_note` articles should be published promptly after a product release.
- Check for deployments stuck in `sending` — may indicate a connection issue.

---

## KB-Specific Audit Events

| Event | Meaning |
|-------|---------|
| `kb_structure.validation_passed` | Structure check passed |
| `kb_structure.validation_failed` | Structure check failed |
| `kb_profile.created` | KB profile created |
| `kb_profile.updated` | KB profile updated |
| `kb_structure.step_flagged_unsafe` | Step content flagged for unsafe instructions |

---

## Rollback of KB Articles

Rollback works identically to blog post rollback:

1. Publishing → Deployments → find the KB deployment
2. Click "Rollback"
3. The article is set to `unpublished` on the public site
4. It is removed from the sitemap

Users attempting to access the unpublished URL will receive a 404 on the public site until a new version is published.
