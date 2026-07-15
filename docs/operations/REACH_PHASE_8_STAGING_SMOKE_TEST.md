# Phase 8 — Staging Smoke Test Plan

**Target environment:** Staging (PostgreSQL, real GSC/GA4 credentials optional)

---

## Pre-Smoke Checklist

- [ ] `git tag reach-phase-8-complete` applied on `main`
- [ ] All 28 migrations from `100144` to `100171` applied: `php spark migrate`
- [ ] Phase 8 env vars populated in staging `.env`

## Smoke Test Cases

### 1. Content Identity Registration
- Create a blog post in staging
- Verify `reach_content_identities` row created with correct `canonical_url`
- Verify `publication_state = draft`

### 2. Sitemap Snapshot
- POST `/api/v1/intelligence/sitemap/snapshot`
- Expect `200` response with snapshot ID
- Verify `reach_sitemap_snapshots` row created

### 3. IndexNow Submission
- POST `/api/v1/intelligence/indexnow/submit` with valid URL
- Expect `200` response
- Verify `reach_indexnow_submissions` row created

### 4. Analytics Connection
- POST `/api/v1/intelligence/analytics/connections` with `provider = gsc`
- Expect `201` response
- Trigger ingestion (with mock if GSC not available)

### 5. Attribution Touchpoint
- POST `/api/v1/intelligence/attribution/touchpoints`
- Expect `201` response
- Verify `reach_attribution_touchpoints` row created

### 6. AI Visibility Run
- Create a visibility prompt with text
- Approve prompt version
- Trigger manual run via mock provider
- Verify `reach_ai_visibility_observations` rows created with `coverage_state`

### 7. Connector Health Check
- POST `/api/v1/intelligence/connectors/health-check`
- Expect `200` response with health status

### 8. Frontend Routes
- Navigate to `/intelligence` — verify overview loads
- Navigate to `/intelligence/search` — verify page loads
- Navigate to `/intelligence/sitemaps` — verify page loads
- Navigate to `/intelligence/visibility` — verify page loads

### 9. Permission Gate
- Attempt `/api/v1/intelligence/search/connections` without `search.connect` permission
- Expect `403 Forbidden`

### 10. Phase 9 Evidence
- Call `IntelligenceEvidenceService::getEvidencePacket()` for a known content identity
- Verify packet keys: `identity`, `search`, `engagement`, `indexing`, `visibility`, `attribution`, `freshness`, `completeness`
