# Phase 8 — Deployment Guide

## Prerequisites

- Phase 7 `reach-phase-7-complete` tag must be on `HEAD` of deployed `main`
- PostgreSQL 14+ with UUID-OSSP extension
- PHP 8.2+
- Node 20+

## Environment Variables

Add to production `.env` before deployment:

```dotenv
# Google Search Console
GSC_ENABLED=true
GSC_CREDENTIALS_JSON=/path/to/service-account.json

# GA4 Content Analytics
GA4_CONTENT_ENABLED=true
GA4_CONTENT_PROPERTY_ID=properties/XXXXXXXXX

# IndexNow
INDEXNOW_ENABLED=true
INDEXNOW_API_KEY=your-indexnow-key
INDEXNOW_ENDPOINT=https://api.indexnow.org/indexnow

# AI Visibility
AI_VISIBILITY_ENABLED=true
AI_VISIBILITY_PROVIDER=openai
```

## Deployment Steps

1. Pull latest `main`:
   ```bash
   git pull origin main
   ```

2. Run migrations:
   ```bash
   cd server-php
   php spark migrate
   ```

3. Verify migration count (should be 100171 as latest):
   ```bash
   php spark migrate:status | tail -5
   ```

4. Build frontend:
   ```bash
   cd web && npm ci && npm run build
   ```

5. Clear caches:
   ```bash
   cd server-php
   php spark cache:clear
   php spark optimize
   ```

6. Apply release tag:
   ```bash
   git tag reach-phase-8-complete
   git push origin reach-phase-8-complete
   ```

## Rollback

See `REACH_PHASE_8_ROLLBACK.md`.
