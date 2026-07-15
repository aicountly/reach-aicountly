# Runbook: Phase 9 Deployment

**Phase:** 9
**Pre-requisites:** All readiness checks passed, release acceptance record created

---

## Pre-deployment checklist

- [ ] PHPUnit Unit suite: green
- [ ] PHPUnit Feature suite: green (PostgreSQL, zero DB-unavailable skips)
- [ ] npm lint: clean
- [ ] npm test: green
- [ ] npm build: successful
- [ ] MigrationLifecycleTest: green
- [ ] aicountly-com tests: green
- [ ] Backup taken: `pg_dump` run and verified
- [ ] Release acceptance record created (reach_release_acceptance_records)

## Deployment steps

```bash
# 1. Pull latest code
git pull origin main

# 2. Run migrations
php spark migrate

# 3. Verify migration status
php spark migrate:status

# 4. Build frontend
cd web && npm run build

# 5. Clear CI4 cache
php spark cache:clear

# 6. Restart application server
# (command depends on hosting environment)

# 7. Verify health check
curl https://app.aicountly.com/api/reach/v1/health
```

## Post-deployment verification

1. Login and verify `/readiness` route loads
2. Verify `/intelligence` route loads
3. Check `reach_readiness_audit_runs` for any automated checks
4. Monitor `reach_jobs` for job worker activity

## Rollback

See `REACH_PHASE9_DISASTER_RECOVERY.md` for rollback procedure.
