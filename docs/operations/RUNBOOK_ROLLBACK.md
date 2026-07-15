# Runbook: Phase 9 Rollback

**Phase:** 9
**Use when:** Critical defect in production requires reverting to Phase 8

---

## Rollback conditions

Rollback to Phase 8 if:
- Critical security vulnerability discovered
- Data corruption in Phase 9 tables
- Migration cannot complete cleanly on production
- Performance degradation > 50% above baseline

## Rollback steps

```bash
# 1. Stop job workers to prevent new Phase 9 operations
# (method depends on hosting)

# 2. Roll back migrations to Phase 8
php spark migrate:rollback --all
php spark migrate --target 100171

# 3. Check out Phase 8 code
git checkout reach-phase-8-complete

# 4. Build frontend
cd web && npm run build

# 5. Clear CI4 cache
php spark cache:clear

# 6. Restart application server

# 7. Verify health check
curl https://app.aicountly.com/api/reach/v1/health
```

## Data safety

Phase 9 tables are additive (no existing table was modified structurally). Data in Phase 9 tables (reach_refresh_*, reach_attribution_models, reach_readiness_*) will be orphaned after rollback. Preserve the database backup for potential forward-migration.

## Public-site receiver

The `refresh_type` field added to the publish endpoint is backwards-compatible (optional field, ignored if absent). No public-site rollback required.
