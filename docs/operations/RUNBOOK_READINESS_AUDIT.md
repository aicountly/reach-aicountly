# Runbook: Readiness Audit Run

**Phase:** 9
**Service:** `ReadinessAuditRunModel`

---

## Audit types

| Type | Trigger | Automated? |
|------|---------|-----------|
| security | Before release | Manual + automated checks |
| privacy | Before release | Manual review |
| ai_governance | Before release | Manual review |
| migration | Any schema change | Automated (MigrationLifecycleTest) |
| performance | Before release | Manual + query analysis |
| operational | Before release | Manual checklist |
| dr | Before release | Manual test + evidence record |

## Process

1. Create audit run: `INSERT INTO reach_readiness_audit_runs (tenant_id, audit_type, status) VALUES (:tid, :type, 'running')`
2. Create findings for each issue found
3. Mark run complete: `UPDATE reach_readiness_audit_runs SET status = 'completed', completed_at = NOW() WHERE id = :id`
4. Resolve all critical/high findings before release

## Blocking findings

Findings with `severity = critical` or `high` in `resolution_status = open` or `in_progress` block release acceptance.
