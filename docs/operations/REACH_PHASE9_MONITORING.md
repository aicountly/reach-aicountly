# Phase 9 — Monitoring and Alerting

**Date:** 2026-07-15

---

## Critical Alerts (immediate response required)

| Alert | Trigger | Response |
|-------|---------|---------|
| Failed publication > 0 | `reach_refresh_publication_links.delivery_status = failed` | Investigate idempotency key, retry or escalate |
| Open critical finding | `reach_readiness_findings.severity = critical AND resolution_status IN (open, in_progress)` | Block deployment, assign remediation immediately |
| Refresh workflow stuck | `reach_refresh_workflows.status = draft_generating AND updated_at < NOW() - 2h` | Check AI generation queue, job worker health |
| Migration lifecycle failure | `phpunit MigrationLifecycleTest` fails in CI | Block merge, escalate to senior engineer |

---

## Warning Alerts (same-day response)

| Alert | Trigger | Response |
|-------|---------|---------|
| Recommendation backlog > 50 | `reach_refresh_recommendations.status = recommended` | Review detection job frequency |
| Pending outcome windows > 20 | `reach_refresh_outcome_windows.measurement_status = pending` | Check outcome measurement job |
| Failed publications count rising | 3+ failed in 24h | Check HMAC signing, aicountly-com receiver health |
| Attribution model not active | No `is_active = true` in `reach_attribution_models` | Activate a model for the tenant |

---

## Health Checks

The following endpoints must return 200 in staging and production:

```
GET /api/reach/v1/health
GET /api/reach/v1/readiness/health
```

---

## Background Job Schedule

| Job | Frequency | Expected Duration |
|-----|-----------|-----------------|
| `ContentRefreshDetectionJob` | Daily 03:00 | < 5 min |
| `RefreshOutcomeMeasurementJob` | Daily 04:00 | < 10 min |
| `AnomalyDetectionJob` | Daily 06:00 | < 5 min |
| `AttributionCalculationJob` | Hourly | < 2 min |
| `ConnectorHealthCheckJob` | Every 30 min | < 1 min |

---

## Logging

- Application logs: CodeIgniter logger, `writable/logs/`
- Audit log: `reach_audit_logs` table, 90-day retention
- Job log: `reach_jobs` table, 30-day retention
- Security log: `reach_security_events` table, 1-year retention

---

## Capacity Guidelines

| Resource | Threshold | Action |
|---------|-----------|--------|
| `reach_refresh_evidence_snapshots` rows | > 500,000 | Archive old snapshots (tenant + date partition) |
| `reach_attribution_allocation_facts` rows | > 2,000,000 | Review retention policy |
| `reach_audit_logs` rows | > 10,000,000 | Rotate to archival table |
| AI generation budget | > 80% monthly | Notify budget owner |
