# Phase 9 — Final Privacy Audit

**Date:** 2026-07-15
**Scope:** Phase 1–9 personal data inventory and controls review

---

## Personal Data Inventory

| Data Element | Table | Pseudonymised? | Retention | Lawful Basis |
|---|---|---|---|---|
| User IDs (internal) | reach_actors | No (internal identifier) | Account duration | Contractual |
| Visitor pseudonym hash | reach_attribution_touchpoints | Yes (SHA-256 hash of session token) | 90 days | Legitimate interest |
| UTM parameters | reach_attribution_touchpoints | No — non-personal | 90 days | Legitimate interest |
| Conversion events | reach_attribution_conversion_links | Pseudonymised via visitor hash | 1 year | Legitimate interest |
| Journey calculations | reach_attribution_journey_calculations | Pseudonymised | 1 year | Legitimate interest |
| Allocation facts | reach_attribution_allocation_facts | Pseudonymised | 1 year | Legitimate interest |
| Audit log actor IDs | reach_audit_logs | Internal actor ID | 90 days | Legal obligation |
| AI generation actor IDs | reach_ai_generation_requests | Internal actor ID | 90 days | Contractual |

---

## Privacy Controls

| Control | Status |
|---------|--------|
| No raw IP address storage | Pass — only pseudonymised visitor hash |
| No raw session token storage | Pass — hash only |
| Visitor data access restricted | Pass — `attribution.read` permission required |
| Attribution identity confidence disclosed | Pass — mandatory `identity_confidence` and `limitations_note` fields |
| No re-identification from allocation facts | Pass — no join path back to individual visitor |

---

## Data Subject Rights

Phase 9 adds no new direct personal data beyond Phase 8.
Attribution data is pseudonymised and cannot be linked to a specific individual without the mapping key (held by the frontend session, never stored server-side).

Erasure requests for pseudonymous visitor data: delete records matching `visitor_pseudonym_hash` from `reach_attribution_touchpoints` and downstream tables. A formal deletion procedure should be documented before production deployment.

---

## Deferred to Production Readiness

- Formal GDPR Data Protection Impact Assessment
- Erasure runbook (see `REACH_PHASE9_DELETION_RUNBOOK.md` — to be created in production readiness phase)
- Cookie consent flow audit (out of Phase 9 scope — handled by public-site)
