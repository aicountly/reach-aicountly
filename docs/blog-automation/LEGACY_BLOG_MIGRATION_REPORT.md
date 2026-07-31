# Legacy Reach Blog Migration Report

## Command

```bash
cd server-php
php spark reach:blog-migrate-legacy --dry-run --report
php spark reach:blog-migrate-legacy --batch-size=50 --resume-from=0 --report
```

## Behaviour

- Source: `reach_blog_posts`
- Target: `reach_content_items` (`content_type=blog`) + version + `reach_content_blog_details`
- Mapping: `reach_blog_legacy_migration_map` + `reach_blog_posts.content_item_id`
- Idempotent: skips when mapping or `content_item_id` already present
- Supports dry-run, batch size, resume, error report

## Fields migrated

Title, slug, excerpt, category, tags, draft content, publication state/date, author, SEO fields, images where present, audit references where available.

## Local execution status

Run on the deployment host against a real PostgreSQL instance. Feature test skips without `TEST_DB_NAME`. Record dry-run totals here after first staging run:

| Metric | Value |
|---|---|
| Total | _(pending staging run)_ |
| Migrated | |
| Skipped | |
| Duplicates | |
| Failed | |
