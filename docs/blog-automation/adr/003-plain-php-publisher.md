# ADR-003: Plain-PHP publisher library on aicountly.com

## Status

Accepted

## Context

`aicountly-com` is plain PHP with no framework. Reach's `AicountlyPublicSitePublisher` already defines the HMAC contract and `/reach/v1/content/*` paths.

## Decision

Implement a hand-rolled `includes/publisher/` library and `reach/v1/` front controller matching existing site style. Composer is added as a **dev-only** dependency for PHPUnit. The receiver owns all private filesystem writes under the resolved storage root; Reach never writes directly to `/home/.../bloguploads`.

## Consequences

- Absolute paths never appear in article rows or API responses.
- PostgreSQL stores relative keys only.
- HMAC canonical string must match Reach's `HmacSigner` exactly.
