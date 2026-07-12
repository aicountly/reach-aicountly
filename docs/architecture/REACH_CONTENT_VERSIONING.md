# Reach — Content Versioning

**Phase 2 — Unified Content Studio**

## Design goals

- Every meaningful save creates an immutable snapshot
- Exactly one version is current at any time
- Version numbers are monotonically increasing per content item
- Concurrency: two simultaneous saves cannot produce the same version number
- Versions are never deleted

---

## Table: `reach_content_versions`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGSERIAL | |
| `uuid` | UUID | |
| `content_item_id` | BIGINT FK | Parent content item |
| `version_number` | INT | 1-indexed, unique per content_item_id |
| `is_current` | BOOLEAN | Exactly one true per content_item_id |
| `body_html` | TEXT | Sanitized HTML (HtmlSanitizer) |
| `body_markdown` | TEXT | Raw markdown if source is markdown |
| `body_plain_text` | TEXT | Plain-text extract for search/diff |
| `structured_payload_json` | JSONB | Type-specific extra fields |
| `change_summary` | VARCHAR(512) | Author note for this version |
| `created_by` | BIGINT FK | |
| `created_at` | TIMESTAMPTZ | |

No `updated_at`, no soft delete — truly immutable.

---

## Version creation flow

`ContentVersionService::create()`:

1. Begin transaction
2. `UPDATE reach_content_versions SET is_current = false WHERE content_item_id = ? AND is_current = true`
3. `SELECT COALESCE(MAX(version_number), 0) + 1 FROM reach_content_versions WHERE content_item_id = ?` (inside transaction = serialisable)
4. `INSERT` new version with `is_current = true` and computed `version_number`
5. Commit

Concurrency protection: the SELECT and INSERT happen inside the same transaction with row-level locking on the content item's version rows.

---

## When versions are created

| Trigger | Behaviour |
|---|---|
| `ContentItemService::create()` | Always creates version 1 |
| `ContentItemService::update()` when `workflow_status != 'approved'` | Creates new version, marks it current |
| `ContentItemService::update()` when `workflow_status == 'approved'` | Creates new version AND resets workflow_status to `draft` |
| Direct `ContentVersionService::create()` via editor save | Creates new version |

---

## Version comparison

`GET /v1/content/items/:id/versions/:a/compare/:b`

Returns both version payloads plus `fields_changed` array (keys that differ between `structured_payload_json` and body fields). The frontend `VersionDiff` component renders a side-by-side diff.

---

## HTML sanitization

All `body_html` content passes through `HtmlSanitizer` (HTMLPurifier) before being stored. The sanitizer configuration allows heading tags, lists, bold, italic, links, and code blocks. Script tags, event attributes, and external resources are stripped.
