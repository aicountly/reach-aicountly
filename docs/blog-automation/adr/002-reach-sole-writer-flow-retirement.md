# ADR-002: Reach is the sole blog writer; Flow blog module retired

## Status

Accepted

## Context

Flow's authenticated `content/posts*` API writes `blog_posts`. The public site reads PostgreSQL `blog_posts` (and falls back to Flow public API). Reach lives in a separate DB and already has a signed publishing client targeting `/reach/v1/*` on the public site — but no receiver existed.

## Decision

1. Reach becomes the sole writer of public blog publications via the signed publisher API.
2. Flow's blog controller, React pages, routes, and sidebar Content section are removed.
3. Flow's `blog_posts` table is marked deprecated and not dropped (public site may still use rows as DB-body fallback during migration).
4. Existing public posts are backfilled into private file packages + `blog_publications` registry.

## Consequences

- Marketing users create/edit blogs only in Reach Blog Command Centre / Content Studio.
- Production WHM must verify which DB Flow's `DB_NAME` pointed at historically.
- Flow public blog API fallback on the public site is disabled once Reach publisher + file rendering are validated.
