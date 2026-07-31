# ADR-004: Canonical public URL is /blogs/{slug}

## Status

Accepted

## Context

Live URLs are `/blogs` (listing) and `/blog/{slug}` (detail). Search engines currently index `/blog/{slug}`.

## Decision

Canonical article URL becomes `https://aicountly.com/blogs/{slug}`. Existing `/blog/{slug}` issues a permanent **301** to `/blogs/{slug}`. Sitemap, structured data, RSS, and Open Graph use the new canonical.

## Consequences

- Temporary ranking volatility possible during redirect transition.
- All new publications emit `/blogs/{slug}` only.
- Legacy `blog-detail.php` redirects retargeted to `/blogs/{slug}`.
