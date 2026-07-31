# ADR-001: Canonical content engine is Content Studio

## Status

Accepted

## Context

Reach has two overlapping blog systems: legacy `reach_blog_posts` (`/blog*`) and unified `reach_content_items` with `content_type=blog` (Content Studio + Publishing).

## Decision

Content Studio (`reach_content_items` + versions, briefs, approvals, schedules, sources, claims, workflow) is the sole canonical engine for blogs. Portfolio classification (`marketing` / `product` / `problem_to_product`) is stored as taxonomy on blog details, not as separate CRUD systems.

## Consequences

- Legacy Marketing Blog Management is migrated, redirected, then deprecated.
- Publishing > Blogs remains and is exposed through Blog Command Centre as a shared page.
- No third independent blog CRUD is created.
