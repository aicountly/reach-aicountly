# Phase 8 — Rollback Guide

## When to Roll Back

Roll back Phase 8 only if:
- Migration failures on production PostgreSQL that cannot be hotfixed
- Critical data corruption in `reach_search_metric_facts` or `reach_content_metric_facts`
- Security vulnerability discovered post-deployment requiring immediate remediation

## Step-by-Step Rollback

### 1. Disable Phase 8 features

Set in `.env`:
```dotenv
GSC_ENABLED=false
GA4_CONTENT_ENABLED=false
INDEXNOW_ENABLED=false
AI_VISIBILITY_ENABLED=false
```

Restart application to pick up env changes. This disables all Phase 8 connectors without touching data.

### 2. Roll back migrations (if required)

```bash
cd server-php
# Roll back from 100171 to 100143 (Phase 7 end)
php spark migrate:rollback --batch=N
```

Where `N` is the batch number for Phase 8 migrations. Find it:
```bash
php spark migrate:status | grep "100144"
```

### 3. Rebuild frontend to Phase 7

```bash
git checkout reach-phase-7-complete
cd web && npm ci && npm run build
```

### 4. Verify rollback

```bash
php spark migrate:status | tail -5
# Should show 100143_AddDistributionPermissions as latest
```

## Data Preservation

Phase 8 tables (`reach_content_identities`, `reach_search_metric_facts`, etc.) retain data after rollback unless explicitly dropped. This is intentional — re-applying Phase 8 migrations will resume from existing data, minimising re-ingestion needs.
