# Reach Product Claim Governance

## Overview

Product claims (`reach_product_claims`) are marketing assertions about a
product that must be governed with appropriate rigour. Claims that are
inaccurate, unsupported, or legally risky can cause reputational or regulatory
damage if surfaced by AI-generated content.

---

## Risk levels

| Risk level | Description | Evidence required to approve |
|---|---|---|
| `low` | General marketing statements | No (but encouraged) |
| `medium` | Performance comparisons, feature differentiators | No (but encouraged) |
| `high` | Benchmark claims, uptime/SLA promises | **Yes — at least 1 approved evidence record** |
| `critical` | Legal/compliance claims, safety assertions | **Yes — at least 1 approved evidence record** |

The `ClaimController::approve()` endpoint enforces this at the API layer:
if `risk_level` is `high` or `critical` AND `requires_evidence = true`,
the controller calls `EvidenceModel::approvedCountForClaim()` and returns
HTTP 422 if the count is 0.

---

## Lifecycle

```
draft → submit (knowledge.submit) → needs_review
needs_review → approve (knowledge.approve) → approved
             → reject  (knowledge.approve) → rejected
approved     → deprecate                  → deprecated
deprecated   → archive                    → archived
```

High/critical claims: `approve` action blocked until at least 1 approved
evidence record is linked.

---

## Temporal validity

Claims have optional `valid_from` and `valid_until` timestamps. The grounding
API filters claims to those that are `approved` AND within their validity
window. This allows seasonal or time-limited claims (e.g. "early-bird pricing")
to be managed without manual deprecation.

---

## Audit trail

Every state transition on a claim is recorded by `AuditLogger::log()` with:
- `actor_id` and `actor_type`
- `request_id` from `X-Request-Id` header
- Old and new status
- Optional rejection reason

Approval decisions are stored in `reach_approvals` using the existing
`ApprovalPolicy` framework.
