# ADR-005: Storage root derivation from environment

## Status

Accepted

## Context

Private blog packages must live outside `public_html`. Absolute paths and cPanel usernames must not be stored in article rows.

## Decision

Environment:

```
BLOG_CPANEL_USER=aicountlycom
BLOG_STORAGE_BASE=/home
BLOG_STORAGE_FOLDER=bloguploads
BLOG_STORAGE_ROOT=   # optional override
```

Resolution:

```
if BLOG_STORAGE_ROOT is non-empty → use it
else → BLOG_STORAGE_BASE / BLOG_CPANEL_USER / BLOG_STORAGE_FOLDER
```

Validate: absolute, not under `public_html`, no traversal, writable by the app. Never expose the root in public API responses.

PostgreSQL stores relative keys such as `published/2026/07/article-slug/v1/content.html`.

## Consequences

- Deployment runbook owns directory creation and ownership on WHM.
- Application code only consumes env-derived roots.
