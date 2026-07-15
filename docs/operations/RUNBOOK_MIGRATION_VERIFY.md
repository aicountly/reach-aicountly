# Runbook: Migration Lifecycle Verification

**Phase:** 9
**Test:** `MigrationLifecycleTest::testFullRollbackAndReapplySucceeds`

---

## Running the test

```bash
cd server-php
php vendor/bin/phpunit tests/Feature/Migrations/MigrationLifecycleTest.php \
  --no-coverage
```

Expected output: All tests pass, including Phase 9 tables in the recreation list.

## If the test fails

| Error | Cause | Fix |
|-------|-------|-----|
| `relation X does not exist` during rollback | FK dependency not handled by CASCADE | Add `ON DELETE CASCADE` or change drop order |
| `relation X does not exist` during up | Migration references table from later migration | Reorder migrations |
| `duplicate key` | Unique constraint violated in test data | Check test fixtures; ensure clean state |

## Recording migration DR test

```php
$service->recordTest(
    testType:          'migration_verify',
    environment:       'local',
    status:            'passed',
    rpoMinutes:        null,
    rtoMinutes:        null,
    procedureFollowed: 'php vendor/bin/phpunit MigrationLifecycleTest',
    evidenceNotes:     'Full rollback and reapply passed, all 100194 migrations verified',
    testedBy:          $actorId,
);
```
