# Reach Knowledge Curation — Operations Guide

## Roles and permissions

| Role | Can create | Can submit | Can approve | Can archive |
|---|---|---|---|---|
| `super_admin` | ✓ | ✓ | ✓ | ✓ |
| `reach_admin` | ✓ | ✓ | ✓ | ✓ |
| `marketing_manager` | ✓ | ✓ | — | — |
| `content_reviewer` | — | — | ✓ | — |
| `analyst` | — | — | — | — |
| `viewer` | — | — | — | — |

---

## Creating knowledge

1. Navigate to **Knowledge Foundation** in the sidebar.
2. Select the entity type (Products, Personas, Industries, etc.).
3. Use the **New** button to create a record in `draft` status.
4. Fill in all required fields. Rich text fields (`description`, `policy_text`,
   `rule_text`) are sanitised with HTMLPurifier before storage.
5. URLs (e.g. source URLs) are validated against `UrlPolicy` on save.

---

## Review and approval workflow

1. Author clicks **Submit for review** — status moves to `needs_review`.
2. A `content_reviewer` or admin reviews the record.
3. To approve: click **Approve**. For high/critical risk claims, at least one
   approved evidence record must be linked first.
4. To reject: click **Reject** and provide an optional reason. The author is
   expected to re-draft and re-submit.

---

## Importing legacy product taxonomy

The `KnowledgeTaxonomyImporter` provides an idempotent import of the legacy
`SaasProductTaxonomy.php` hardcoded product map into the database:

```bash
php spark reach:import-taxonomy
```

This command is **safe to run multiple times**. It will:
- Create new products with status `needs_review`
- Skip products whose `slug` already exists in the database
- Skip products that have been `approved` (preserves administrator changes)
- Create product aliases for legacy code names

---

## Monitoring completeness

Navigate to **Knowledge > Completeness** to see a score for each product.
The completeness dashboard ranks products from least to most complete.

A product is **AI-ready** only when:
- All 12 dimensions score above their minimum threshold
- No unsupported approved claims (high/critical claims need evidence)
- No expired evidence
- Status is `approved`

---

## Soft deletes

Knowledge records are soft-deleted (`deleted_at IS NOT NULL`). Deleted records
do not appear in any UI or API response by default. To permanently purge records,
contact a `super_admin` who can do so via raw DB access or a future purge
command.

---

## Audit log

All create, update, approve, reject, and archive actions on knowledge entities
are logged to `reach_audit_log` with:
- Actor ID and type
- `X-Request-Id` for request correlation
- Before/after JSON snapshots (secrets redacted)

View logs at **Administration > Audit Logs** (requires `audit.view` permission).
