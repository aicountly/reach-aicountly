# Phase 8 Discovery Report

**Date:** 2026-07-15  
**Baseline:** `reach-phase-7-complete` → `ea09688c527cb6f7f2df78753c5198abd8ed10e8`

---

## Existing Infrastructure Inventory

### Analytics

| Component | Status | Location |
|-----------|--------|----------|
| `Ga4AnalyticsClient` | Implemented | `server-php/app/Libraries/Ga4AnalyticsClient.php` |
| `TrafficAnalyticsService` | Implemented | `server-php/app/Libraries/TrafficAnalyticsService.php` |
| `AnalyticsCache` | Implemented | `server-php/app/Libraries/AnalyticsCache.php` |
| `reach_analytics_snapshots` | Implemented | Migration 100018; source CHECK includes `gsc` |
| `AnalyticsController` | Implemented | `server-php/app/Controllers/Api/V1/AnalyticsController.php` |
| GA4 Property ID env | Implemented | `GA4_PROPERTY_ID_*` in `.env.example` |
| GSC API client | **Missing** | Only placeholder `GSC_SITE_URL` in `.env.example` |

### Sitemap

| Component | Status | Location |
|-----------|--------|----------|
| `SitemapVerificationService` | Implemented | `server-php/app/Libraries/Publishing/Seo/SitemapVerificationService.php` |
| `IndexingReadinessService` | Implemented | `server-php/app/Libraries/Publishing/Seo/IndexingReadinessService.php` |
| Sitemap generator | **Missing** | Not implemented in Reach (public site generates its own) |
| IndexNow | **Missing** | No code exists |

### SEO / Canonical

| Component | Status | Location |
|-----------|--------|----------|
| `CanonicalUrlPolicy` | Implemented | `server-php/app/Libraries/Publishing/Seo/CanonicalUrlPolicy.php` |
| `reach_content_seo_profiles` | Implemented | Migration 100075 |
| `SeoProfileController` | Implemented | `server-php/app/Controllers/Api/V1/Publishing/SeoProfileController.php` |
| Canonical content identity (cross-type) | **Missing** | Not implemented |

### Attribution / Leads

| Component | Status | Location |
|-----------|--------|----------|
| `reach_leads` | Implemented | Earlier migration |
| `reach_engage_pushes` | Implemented | Migration 100046 |
| Campaign UTM fields | Implemented | `reach_campaigns` has UTM-related fields |
| UTM template management | **Missing** | Not implemented |
| Attribution touchpoints | **Missing** | Not implemented |
| First/last-touch calculation | **Missing** | Not implemented |

### AI Visibility

| Component | Status | Location |
|-----------|--------|----------|
| AI provider registry | Implemented | Phase 3 `server-php/app/Libraries/Ai/` |
| AI generation orchestrator | Implemented | `AiGenerationOrchestrator.php` |
| Budget / usage ledger | Implemented | `AiBudgetService.php`, usage ledger |
| `OutputSchemaRegistry` | Implemented | Phase 3 |
| Visibility prompt library | **Missing** | Not implemented |
| AI visibility runs | **Missing** | Not implemented |
| Competitor monitoring | **Missing** | Not implemented |

### Job Infrastructure

| Component | Status |
|-----------|--------|
| `JobService` | Implemented (Phase 0) |
| `JobHandlerRegistry` | Implemented |
| Distribution job types | Implemented (Phase 7) |
| Intelligence job types | **Missing** |

### Permissions (existing relevant slugs)

- `analytics.view`, `community_analytics.view` — existing
- `seo.*`, `aeo.*` — existing
- No `intelligence.*`, `search.*`, `attribution.*`, `visibility.*`, `competitor.*` slugs exist

---

## Last Migration Sequence

```
100143 — AddDistributionPermissions (Phase 7 final)
```

Phase 8 migrations start at **100144**.

---

## Phase 8 New Tables Required

| # | Table | Purpose |
|---|-------|---------|
| 100144 | `reach_content_identities` | Canonical content identity spanning all types |
| 100145 | `reach_content_publication_mappings` | Identity to platform mappings |
| 100146 | `reach_sitemap_snapshots` | Point-in-time sitemap state |
| 100147 | `reach_sitemap_entries` | Per-URL sitemap entries |
| 100148 | `reach_indexnow_submissions` | IndexNow submission records |
| 100149 | `reach_indexnow_attempts` | Per-attempt delivery log |
| 100150 | `reach_analytics_connections` | GSC/GA4 connector configuration |
| 100151 | `reach_analytics_ingestion_cursors` | Ingestion position per connector/stream |
| 100152 | `reach_search_metric_facts` | Daily GSC facts (query/page/device/country) |
| 100153 | `reach_content_metric_facts` | Daily GA4 per-content facts |
| 100154 | `reach_analytics_ingestion_runs` | Ingestion run lifecycle |
| 100155 | `reach_content_mapping_findings` | Unmapped URL / conflict findings |
| 100156 | `reach_utm_templates` | Governed UTM template management |
| 100157 | `reach_attribution_touchpoints` | Visit/click attribution evidence |
| 100158 | `reach_attribution_conversion_links` | Lead/conversion linkage |
| 100159 | `reach_attribution_calculation_versions` | Versioned first/last-touch calculations |
| 100160 | `reach_ai_visibility_prompts` | Visibility prompt definitions |
| 100161 | `reach_ai_visibility_prompt_versions` | Immutable prompt versions |
| 100162 | `reach_ai_visibility_runs` | Execution run records |
| 100163 | `reach_ai_visibility_responses` | Immutable raw provider responses |
| 100164 | `reach_ai_visibility_observations` | Parsed mentions and classifications |
| 100165 | `reach_ai_visibility_citations` | Extracted citations and linked domains |
| 100166 | `reach_competitors` | Competitor organisation definitions |
| 100167 | `reach_competitor_aliases` | Competitor product aliases and domains |
| 100168 | `reach_competitor_observation_aggregates` | Aggregated competitor mention stats |
| 100169 | `reach_connector_health` | Connector health check records |
| 100170 | `reach_metric_freshness` | Per-source freshness tracking |
| 100171 | `AddIntelligencePermissions` | Permission seeder migration |

---

## Public-Site Assessment

Inspected `aicountly-com` baseline: `aicountly-public-phase-5-complete` tag exists. No Phase 6 or Phase 7 tags found that materially affect public-site changes.

**Decision:** Use `aicountly-public-phase-5-complete` as public baseline. No `aicountly-com` files will be changed in Phase 8.

---

## Provider and Credential Strategy

| Connector | Credential Type | Storage Strategy |
|-----------|----------------|-----------------|
| Google Search Console | OAuth2 service account or user OAuth | Secure env reference; never in repo |
| GA4 (existing) | Service account JSON | Already in `.env` as reference path |
| IndexNow | API key | Env variable reference |
| AI visibility | Reuses Phase 3 AI provider registry | Existing credential management |

---

## Phase 9 Evidence Preparation

Phase 8 will lay evidence for Phase 9 (content refresh automation):
- `reach_search_metric_facts` — position/CTR decline time series
- `reach_content_metric_facts` — engagement decline time series
- `reach_sitemap_entries` — indexing gaps
- `reach_ai_visibility_observations` — AI visibility coverage gaps
- Attribution completeness state

Phase 9 refresh recommendation engine and refresh queues are explicitly **not** implemented.
