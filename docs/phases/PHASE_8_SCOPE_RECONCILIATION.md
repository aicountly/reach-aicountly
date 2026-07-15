# Phase 8 Scope Reconciliation

**Phase:** 8
**Title:** Search Intelligence, Attribution and AI Visibility
**Date:** 2026-07-15
**Baseline tag:** `reach-phase-7-complete` → `ea09688c527cb6f7f2df78753c5198abd8ed10e8`

---

## Capability Inventory

### Capability 29 — Sitemap and IndexNow

| Item | Classification | Evidence | Phase 8 Treatment |
|------|---------------|----------|-------------------|
| Sitemap generation | Inferred requirement | Phase 4 has `SitemapVerificationService` (reads public sitemap, does not generate) | Implement `SitemapSnapshotService` to build internal sitemap from canonical identities |
| Sitemap index | Confirmed requirement | Not implemented | New |
| IndexNow submission | Confirmed requirement | No application code | New — `IndexNowSubmissionService` |
| Noindex exclusion | Confirmed requirement | `reach_content_seo_profiles.robots` field exists | Extend via canonical identity exclusion rules |
| Withdrawn-content exclusion | Confirmed requirement | Publication status fields exist | Filter canonical identities with withdrawn status |
| Sitemap validation | Confirmed requirement | `SitemapVerificationService` validates format | Extend/reuse |
| Sitemap freshness | Confirmed requirement | Not implemented | New — `reach_sitemap_snapshots` |

### Capability 30 — Search Analytics (Google Search Console)

| Item | Classification | Evidence | Phase 8 Treatment |
|------|---------------|----------|-------------------|
| GSC connection management | Confirmed requirement | `.env.example` has `GSC_SITE_URL`; `gsc` in snapshot source CHECK | New — `reach_analytics_connections` |
| GSC incremental ingestion | Confirmed requirement | Not implemented | New — `SearchConsoleService` |
| GSC cursor/backfill | Confirmed requirement | Not implemented | New — `reach_analytics_ingestion_cursors` |
| GSC fact storage | Confirmed requirement | Not implemented | New — `reach_search_metric_facts` |
| Content mapping (URL→identity) | Confirmed requirement | Not implemented | New — `reach_content_identities` |
| Unmapped page reporting | Confirmed requirement | Not implemented | New — `reach_content_mapping_findings` |

### Capability 31 — Content Analytics (GA4 per-content)

| Item | Classification | Evidence | Phase 8 Treatment |
|------|---------------|----------|-------------------|
| GA4 site-level analytics | Already implemented | `Ga4AnalyticsClient`, `TrafficAnalyticsService`, `reach_analytics_snapshots` | Reuse and extend |
| Per-content GA4 metrics | Confirmed requirement | Not implemented | New — `reach_content_metric_facts` |
| Per-content cursor | Confirmed requirement | Not implemented | New — `reach_analytics_ingestion_cursors` |
| Canonical URL mapping | Inferred requirement | `CanonicalUrlPolicy` exists | Extend for content-identity resolution |
| Engagement metrics | Confirmed requirement | Site-level only | Per-content extension |
| Unmapped analytics path reporting | Confirmed requirement | Not implemented | New — `reach_content_mapping_findings` |

### Capability 32 — Lead Attribution Foundations

| Item | Classification | Evidence | Phase 8 Treatment |
|------|---------------|----------|-------------------|
| UTM templates | Confirmed requirement | Campaign UTM fields exist; no template management | New — `reach_utm_templates` |
| Attribution touchpoints | Confirmed requirement | Not implemented | New — `reach_attribution_touchpoints` |
| First-touch / last-touch | Confirmed requirement | Not implemented | New — `reach_attribution_calculation_versions` |
| Engage handoff linkage | Confirmed requirement | `reach_engage_pushes` exists | Extend via touchpoint linkage |
| Lead-conversion links | Confirmed requirement | Not implemented | New — `reach_attribution_conversion_links` |
| Multi-touch weighting | Deferred requirement | Phase 9 scope | Not implemented in Phase 8 |
| Revenue attribution | Deferred requirement | Phase 9 scope | Not implemented |

### Capability 33 — AI Visibility Monitoring

| Item | Classification | Evidence | Phase 8 Treatment |
|------|---------------|----------|-------------------|
| AI provider registry | Already implemented | Phase 3 `AiGenerationOrchestrator`, routing, budget ledger | Reuse |
| Visibility prompt library | Confirmed requirement | Not implemented | New — `reach_ai_visibility_prompts` |
| Immutable prompt versions | Confirmed requirement | Phase 3 `AiPrompt` versioning pattern | New — `reach_ai_visibility_prompt_versions` |
| AI visibility runs | Confirmed requirement | Not implemented | New — `reach_ai_visibility_runs` |
| Raw response storage | Confirmed requirement | Not implemented | New — `reach_ai_visibility_responses` |
| Mention/citation extraction | Confirmed requirement | Not implemented | New — `reach_ai_visibility_observations`, `reach_ai_visibility_citations` |
| Budget enforcement | Already implemented | Phase 3 `AiBudgetService` | Reuse |
| Usage ledger | Already implemented | Phase 3 usage ledger | Reuse |

### Capability 34 — Competitor Visibility Monitoring

| Item | Classification | Evidence | Phase 8 Treatment |
|------|---------------|----------|-------------------|
| Competitor definitions | Confirmed requirement | Not implemented | New — `reach_competitors` |
| Competitor aliases | Confirmed requirement | Not implemented | New — `reach_competitor_aliases` |
| Observation mapping | Confirmed requirement | Not implemented | New — `reach_competitor_observation_aggregates` |
| Market-share claims | Out of scope | Prohibited | Not implemented |
| Prohibited scraping | Out of scope | Prohibited | Not implemented |

---

## Explicit Exclusions

- Content refresh automation
- Phase 9 refresh queues
- Multi-touch attribution weighting
- Revenue attribution without verified evidence
- Autonomous campaign optimisation
- Autonomous SEO changes
- Competitor scraping breaching provider terms
- Phase 9 programme-readiness audit

---

## Public-Site Impact

No `aicountly-com` changes required for Phase 8. The existing public sitemap at `aicountly-com` is read by `SitemapVerificationService`; Phase 8 builds an internal sitemap snapshot from Reach canonical identities. No public routes will be modified.

**Public-site baseline:** `aicountly-public-phase-5-complete` → `2860693c7ca74267d7b9a6bc527842a81ffbe307`

---

## Dependencies on Prior Phases

| Foundation | Source Phase | Phase 8 Use |
|-----------|-------------|-------------|
| `reach_campaigns` + `reach_campaign_versions` | Phase 7 | Campaign-to-content attribution linkage |
| `reach_campaign_delivery_attempts` | Phase 7 | Distribution attribution evidence |
| `reach_channel_consents` + `reach_channel_suppressions` | Phase 7 | Attribution privacy controls |
| `Ga4AnalyticsClient` | Phase 3/4 | Per-content analytics ingestion |
| `CanonicalUrlPolicy` | Phase 4 | Canonical URL resolution |
| `reach_content_seo_profiles` | Phase 4 | SEO/canonical metadata |
| `AiGenerationOrchestrator` | Phase 3 | AI visibility execution |
| `reach_publications` / `reach_deployment_records` | Phase 4 | Canonical URL + publication date |
| `JobService` | Phase 0 | All async ingestion jobs |
| `AuditLogger` | Phase 0 | All audit trails |
| `ApprovalPolicy` | Phase 2 | Visibility prompt approval |
