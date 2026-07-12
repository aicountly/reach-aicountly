# Reach Knowledge Graph — Architecture

## Overview

The Reach Knowledge Graph is a normalised, approval-gated store of marketing
knowledge that grounds AI-generated content. It replaces the previous approach
of hardcoded product descriptions with a structured, version-controlled, and
audited database of entities.

**No AI-provider calls are made inside this module.** The graph is purely
deterministic: it stores and retrieves approved human-authored facts.

---

## Entity model

```
reach_products
  ├── reach_product_aliases        (brand names, legacy codes)
  ├── reach_product_modules
  │     └── reach_product_features
  │           └── reach_feature_problems   ─── reach_business_problems
  ├── reach_product_claims
  │     └── reach_claim_evidence          ─── reach_evidence
  ├── reach_brand_rules
  ├── reach_product_personas        ─── reach_personas
  ├── reach_product_industries      ─── reach_industries
  └── reach_product_markets         ─── reach_markets

reach_search_intents
  ├── reach_intent_products
  ├── reach_intent_modules
  ├── reach_intent_features
  ├── reach_intent_personas
  └── reach_intent_topic_clusters   ─── reach_topic_clusters

reach_evidence
  └── reach_citations               ─── reach_sources

reach_content_policies
```

---

## Status lifecycle

All primary entities share the same status progression:

```
draft → needs_review → approved
                    └→ rejected → draft (re-draft)
approved → deprecated → archived
```

Only `approved` records are returned by the Grounding API.
Draft and rejected records are never exposed externally.

---

## Design decisions

| Decision | Rationale |
|---|---|
| Platform-level, no tenant scope | Knowledge is shared across all marketing operations |
| `BIGSERIAL` primary keys + UUID external IDs | Consistent with Phase 0 tables |
| Soft deletes on all primary tables | Audit trail preservation |
| `JSONB internal_notes` excluded from grounding | Prevents internal commentary leaking to AI callers |
| `valid_from / valid_until` on claims and evidence | Time-bounded accuracy enforcement |
| `CHECK` constraints on all enum columns | DB-level enum enforcement without migration friction |

---

## Indexes

Every table has:
- Unique index on `(slug)` for human-readable external references
- Index on `(status)` for approval queue filtering
- Index on `(deleted_at)` for soft-delete query performance
- Foreign key indexes on all `product_id`, `module_id` etc. columns

---

## Cross-references

- [Source & Citation Model](REACH_SOURCE_AND_CITATION_MODEL.md)
- [Product Claim Governance](REACH_PRODUCT_CLAIM_GOVERNANCE.md)
- [Grounding API](REACH_GROUNDING_API.md)
