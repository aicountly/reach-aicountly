# Phase 9 — Staging Smoke Test Plan

**Date:** 2026-07-15

---

## Prerequisites

- [ ] Staging database populated with Phase 9 migrations
- [ ] Frontend build deployed to staging
- [ ] Job workers running
- [ ] `REACH_HMAC_SECRET` environment variable set

---

## Test Cases

### Refresh Policy

| Step | Expected |
|------|---------|
| Create refresh policy via admin API | HTTP 201, policy created |
| Create policy version | HTTP 201, version_number = 1 |
| Approve policy version | HTTP 200, approved_by set |
| Activate policy | HTTP 200, is_active = true |

### Recommendation Generation

| Step | Expected |
|------|---------|
| Trigger ContentRefreshDetectionJob with tenant_id | HTTP 200, recommendations_generated >= 0 |
| Check recommendation backlog | Recommendations with status = recommended present |
| Triage a recommendation | HTTP 200, status = triaged |
| Accept recommendation | HTTP 200, status = accepted |

### Refresh Workflow

| Step | Expected |
|------|---------|
| Create workflow from accepted recommendation | HTTP 201, status = accepted |
| Create brief | HTTP 201, brief linked to workflow |
| Request draft generation | HTTP 200, generation_request_id returned |
| Transition to in_review | HTTP 200, lock_version incremented |
| Approve workflow (different actor) | HTTP 200, approved_by set |
| Queue for publication | HTTP 200, idempotency_key generated |

### Attribution

| Step | Expected |
|------|---------|
| Create attribution model | HTTP 201, model_name = equal_weight |
| Create model version | HTTP 201, version_number = 1 |
| Calculate journey for conversion | HTTP 200, allocation_weight sums ≈ 1.0 |

### Readiness

| Step | Expected |
|------|---------|
| Load /readiness | HTTP 200, overview page loads |
| Load /readiness/refresh | HTTP 200, backlog page loads |
| Load /readiness/release | HTTP 200, empty acceptance record page |

---

## Pass Criteria

All steps above must return expected HTTP status. No 500 errors. No database errors in application logs.
