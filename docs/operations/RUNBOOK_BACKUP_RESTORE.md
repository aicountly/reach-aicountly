# Runbook: Backup and Restore

**Phase:** 9

---

## Daily backup procedure

```bash
# Export with compression
pg_dump -Fc -h $DB_HOST -U $DB_USER $DB_NAME \
  -f "reach_backup_$(date +%Y%m%d_%H%M%S).dump"

# Verify
pg_restore --list reach_backup_*.dump | wc -l
```

## Restore to staging (verification)

```bash
# 1. Create fresh database
createdb -h $STAGING_DB_HOST -U $DB_USER reach_restore_verify

# 2. Restore
pg_restore -Fc -h $STAGING_DB_HOST -U $DB_USER \
  -d reach_restore_verify reach_backup_*.dump

# 3. Verify Phase 9 tables
psql -h $STAGING_DB_HOST -U $DB_USER reach_restore_verify -c "
SELECT COUNT(*) FROM reach_refresh_policies;"

# 4. Record DR test evidence
# Use DisasterRecoveryService::recordTest() to create evidence record
```

## Recording backup verification DR test

```php
$service->recordTest(
    testType:          'backup_verify',
    environment:       'staging',
    status:            'passed',
    rpoMinutes:        240,
    rtoMinutes:        120,
    procedureFollowed: 'pg_dump with -Fc, verified with --list',
    evidenceNotes:     'All 100194 migration tables present after restore',
    testedBy:          $actorId,
);
```
