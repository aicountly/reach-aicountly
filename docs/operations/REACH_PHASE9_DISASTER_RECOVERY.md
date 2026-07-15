# Phase 9 — Disaster Recovery Procedures

**Date:** 2026-07-15

---

## Recovery Objectives

| Scenario | RPO Target | RTO Target |
|----------|-----------|-----------|
| Database loss | 4 hours | 2 hours |
| Migration rollback | 0 (no data loss) | 30 minutes |
| Provider API failure | 0 (queued delivery) | Automatic retry |
| Deployment rollback | 0 (no data loss) | 15 minutes |

---

## Backup Procedure

```bash
# PostgreSQL backup (run as postgres user or with pg credentials)
pg_dump -Fc -h $DB_HOST -U $DB_USER $DB_NAME > reach_backup_$(date +%Y%m%d_%H%M%S).dump

# Verify backup integrity
pg_restore --list reach_backup_*.dump | head -20
```

**Frequency:** Daily minimum; before every deployment  
**Retention:** 30 days  
**Location:** Encrypted off-site storage (see infrastructure docs)

---

## Restore Procedure (Local / Staging Only)

```bash
# Create restore target database
createdb -h $DB_HOST -U $DB_USER reach_restore_test

# Restore
pg_restore -Fc -h $DB_HOST -U $DB_USER -d reach_restore_test reach_backup_*.dump

# Verify row counts
psql -h $DB_HOST -U $DB_USER reach_restore_test -c "
SELECT tablename, n_live_tup
FROM pg_stat_user_tables
WHERE n_live_tup > 0
ORDER BY n_live_tup DESC LIMIT 20;"
```

---

## Migration Rollback Procedure

```bash
# Roll back to specific version (example: roll back to Phase 8)
php spark migrate:rollback --all      # roll back all migrations
php spark migrate --target 100171     # re-apply up to Phase 8 baseline

# Verify rollback
php spark migrate:status
```

---

## Application Rollback

```bash
# Revert to previous git tag
git checkout reach-phase-8-complete

# Run migrations to target version
php spark migrate --target 100171

# Rebuild frontend assets
cd web && npm run build

# Restart application server
# (command depends on hosting environment)
```

---

## DR Test Evidence Required

Before release acceptance, the following must be recorded in `reach_disaster_recovery_tests`:

| Test | Environment | Required |
|------|-------------|---------|
| `backup_verify` | local or staging | Yes |
| `restore_verify` | local or staging | Yes |
| `rollback_verify` | local or staging | Yes |
| `migration_verify` | local or staging | Yes |

Record evidence via `DisasterRecoveryService::recordTest()`.
