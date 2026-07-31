# 06 — Data / Migration Evidence

## Reach — new migrations (this engagement)

| Migration | Purpose |
|---|---|
| `2026-07-31-200001_CreateReachBlogContentApprovals.php` | Version-checksum-bound approval records (F-16) |
| `2026-07-31-200002_CreateReachBlogProtectedReviewers.php` | Protected named-reviewer registry ("CA Rahul Gupta" pattern, reused from Community) |
| `2026-07-31-200003_WidenReachContentItemsWorkflowStatusCheck.php` | Widens the `workflow_status` CHECK constraint to cover the full state-machine vocabulary added in Pass 2 |
| `2026-07-31-200004_AddIndexingStatusToBlogPublicationProfiles.php` | Adds `indexing_status` so "published" is never conflated with "indexed" (F-27) |

Reviewed by direct inspection for syntax, foreign keys, and CHECK-constraint correctness. **Cannot be executed in this sandbox** (`php spark migrate:status` fails — no `pdo_pgsql`, no live Postgres; see [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md)). Must be run (`php spark migrate`) against a real Postgres instance before deployment — see [08_DEPLOYMENT_ACTIONS.md](08_DEPLOYMENT_ACTIONS.md).

Pre-existing migration `2026-07-13-100083_CreateReachPublicationDeployments.php` was inspected (not modified) to confirm the `status` CHECK constraint already includes both `rolled_back` and `unpublished` as distinct values, and the `operation` CHECK constraint already includes `restore`, `unpublish`, and `rollback` as distinct values — this is what proved finding F-09 (the schema always intended rollback and unpublish to be different operations; the code just never called `restore()`).

## Public site (`aicountly-com`) — new migration (this engagement)

| Migration | Purpose |
|---|---|
| `database/migrations/009_blog_publications_robots_directive.pgsql.sql` | Adds `robots_directive VARCHAR(40) NOT NULL DEFAULT 'index,follow'` + index to `blog_publications`, so the sitemap can honestly exclude `noindex` content (F-25) |

```sql
ALTER TABLE blog_publications
    ADD COLUMN IF NOT EXISTS robots_directive VARCHAR(40) NOT NULL DEFAULT 'index,follow';

CREATE INDEX IF NOT EXISTS idx_blog_publications_robots_directive
    ON blog_publications(robots_directive);
```

`IF NOT EXISTS` guards make this migration idempotent/safe to re-run. Sequenced after the pre-existing `008_community_receiver_schema.pgsql.sql` (out of scope, separate engagement) with no numbering collision.

## Backfill (public-site body → file package)

The pre-existing Opus backfill script (`--dry-run`/`--batch-size`/`--resume-from`/`--only-id`/`--verify`/`--report`) was reviewed for the `package_migrated` flag-preservation fix (F-22: `PublicationRegistry` no longer drops this flag on a subsequent `create`). **Live `--dry-run` execution against real historical `blog_posts` rows was not performed** — this repository has no production database snapshot available in this sandbox, and running it against a live production database is explicitly out of scope per §29/out-of-scope. This is an `ENVIRONMENT_ONLY`/`PRODUCTION_OPERATION` item — see [08_DEPLOYMENT_ACTIONS.md](08_DEPLOYMENT_ACTIONS.md) for the exact operator command to run before activation.

## Storage/path verification (§14)

- `BLOG_CPANEL_USER`/`BLOG_STORAGE_BASE`/`BLOG_STORAGE_FOLDER`/`BLOG_STORAGE_ROOT` confirmed environment-driven in `server-php/.env.example` and the corresponding `aicountly-com` config; no hard-coded `aicountlycom` string was found written into any article-level database row or Reach business-logic file (only in config/env templates, which is the intended location).
- Newly generated content's published-package path stores a **relative storage key** + checksum; no absolute filesystem path is written per-article.

## New-content body storage verification (§30 "Storage and publishing")

Confirmed by code inspection of `ContentVersionService` and `BlogPublicationPayloadBuilder`: the automation path for newly generated drafts stores structured content (title/summary/body_html/body_markdown/body_plain_text) in `reach_content_versions` as the **editorial working copy** (required for diffing/versioning/approval), while the file package sent to the public receiver via the signed publisher API is what the public site actually renders from (`includes/publisher/PackageWriter.php` writes `content.md`/`content.html`/manifest/sources/verification/seo/hero/images to a private, versioned directory). The public site's `blog_posts.content` legacy full-body column is not written by the new automation path — new articles are file-package-based on the public side, satisfying the §26/§30 "newly generated complete article body is not stored in PostgreSQL [for the published package path]" requirement.
