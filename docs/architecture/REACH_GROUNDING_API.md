# Reach Grounding API

## Overview

The Grounding API returns deterministic, approved-only knowledge context
suitable for injecting into AI prompts. It makes **no AI-provider calls**
and **never returns draft, rejected, or internally-noted data**.

Base path: `/api/v1/knowledge/grounding/`

---

## Endpoints

### `GET /api/v1/knowledge/grounding/product/{slug}`

Returns the full approved knowledge context for a single product.

**Authentication:** Bearer JWT  
**Permission:** `knowledge.view`

**Success response (200):**

```json
{
  "ok": true,
  "data": {
    "product": {
      "id": 1,
      "slug": "reach-ai",
      "name": "Reach AI",
      "short_description": "...",
      "description": "...",
      "public_url": "https://aicountly.com/reach-ai",
      "aliases": ["Reach", "Reach Marketing AI"],
      "modules": [...],
      "features": [...],
      "personas": [...],
      "industries": [...],
      "markets": [...],
      "claims": [...],
      "brand_rules": [...],
      "content_policies": [...]
    }
  }
}
```

**Not found / draft (404):** Returns a generic error message that does not
reveal whether the product exists but is in draft, or does not exist at all.

---

### `GET /api/v1/knowledge/grounding/intent/{id}`

Returns approved intent context including mapped products, features, and personas.

**Permission:** `knowledge.view`

---

### `POST /api/v1/knowledge/grounding/context`

Multi-entity context assembly for complex prompts.

**Request body:**

```json
{
  "product_slugs": ["reach-ai"],
  "channel": "blog"
}
```

**Response:** Aggregated context for all specified products, filtered by
channel-relevant content policies. Returns only approved records.

---

## Invariants

- `internal_notes` is never included in any grounding response
- `approval_reason` and `rejection_reason` are never included
- Feature `availability = 'planned'` is returned as `{ "available": false, "planned": true }`
  — never collapsed to `available: true`
- Claims outside their `valid_from / valid_until` window are excluded
- Evidence with `valid_until < NOW()` is excluded

---

## Rate limiting

The grounding endpoints share the `throttle:knowledge` filter (60 req/min per
user). For high-throughput batch use, see the `context` endpoint which
assembles multiple products in a single request.
