# Reach Source and Citation Model

## Overview

Sources and citations form the evidence chain that supports product claims and
allows content to be traced back to primary references.

---

## Entity hierarchy

```
reach_sources          (a website, document, or data provider)
  └── reach_citations  (a specific passage or page from that source)
        └── reach_evidence  (synthesised evidence using one or more citations)
              └── reach_claim_evidence  (link evidence to a product claim)
```

A single source (e.g. the official Reach AI product page) may have many
citations. A single evidence record may reference multiple citations. Evidence
records are linked to product claims via the `reach_claim_evidence` junction
table.

---

## Source types

| Type | Description |
|---|---|
| `official_docs` | First-party product documentation |
| `press_release` | Published press releases |
| `third_party` | Independent analyst or media coverage |
| `community` | Community forums, user-generated content |
| `internal` | Internal research, proprietary benchmarks |

### Authority score

Each source has an `authority_score` integer (0–100) set by editors. This is
purely informational and does not affect grounding logic. The grounding API
returns it so consumers can weigh sources when building prompts.

---

## Evidence types

| Type | Description |
|---|---|
| `benchmark` | Performance benchmark data |
| `case_study` | Customer implementation story |
| `whitepaper` | Research paper |
| `demo` | Product demonstration recording |
| `changelog` | Official release notes |
| `support_article` | Help centre article |
| `third_party_report` | External research report |
| `internal` | Internal research |

---

## URL validation

All source URLs are validated by `UrlPolicy::validate()` before insert and
update. This prevents SSRF by blocking:

- Non-HTTP(S) schemes
- Loopback addresses (127.0.0.1, ::1)
- AWS/GCP metadata endpoints
- RFC 1918 private IP ranges
- URLs containing userinfo

---

## Expiry

Evidence records have optional `valid_from` and `valid_until` fields.
The `KnowledgeCompletenessService` warns when approved evidence has expired
(`valid_until < NOW()`). Expired evidence does not automatically block grounding
but reduces the product's completeness score and blocks `ai_ready = true`.
