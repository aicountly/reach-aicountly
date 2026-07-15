# Reach Search and Visibility Intelligence Architecture

**Phase:** 8
**Capabilities:** 29 (Sitemap/IndexNow), 30 (Search Analytics), 31 (Content Analytics), 32 (Attribution), 33 (AI Visibility), 34 (Competitor Visibility)

---

## Overview

Phase 8 adds a governed intelligence layer on top of the Phases 0–7 content, publishing, distribution, and AI infrastructure. It measures how AICOUNTLY content performs across search engines, owned properties, and AI-answer environments.

```
┌─────────────────────────────────────────────────────────────┐
│                  Intelligence Control Centre                 │
│     /intelligence/* (React pages + API controllers)         │
├──────────────┬──────────────┬──────────────┬────────────────┤
│  Search      │  Content     │  Attribution │  AI Visibility │
│  (GSC)       │  (GA4)       │  (UTM/Touch) │  (Prompts/Obs) │
├──────────────┴──────────────┴──────────────┴────────────────┤
│           Canonical Content Identity Layer                   │
│           reach_content_identities                          │
│           reach_content_publication_mappings                │
├──────────────────────────────────────────────────────────────┤
│                 Connector Abstraction Layer                   │
│  SearchConsoleConnectorInterface                            │
│  ContentAnalyticsConnectorInterface                         │
│  IndexNowConnectorInterface                                 │
├──────────────────────────────────────────────────────────────┤
│              External Providers (via mocks)                  │
│  Google Search Console API  │  GA4 Data API  │  IndexNow    │
└─────────────────────────────────────────────────────────────┘
```

---

## Canonical Content Identity

Every piece of content in Reach (blog, KB article, community Q&A, video, campaign variant) receives one `reach_content_identities` record per tenant. This is the pivot for all Phase 8 intelligence:

- Search Console page URLs → resolved to identity
- GA4 page paths → resolved to identity
- Attribution touchpoints → link to identity
- AI visibility observations → reference monitored queries against identity

---

## Connector Architecture

All external data ingestion uses the connector interface pattern:

```php
interface SearchConsoleConnectorInterface {
    public function healthCheck(): bool;
    public function fetchMetrics(IngestionRequest $req): MetricBatch;
    public function isEnabled(): bool;
    public function providerName(): string;
}
```

Mock connectors are used in all automated tests. No test may call a live external provider.

---

## Metric Provenance

Every metric fact row records:
- `source_connector` — which connector produced it
- `ingestion_run_id` — which specific run ingested it
- `provider_date` — the date the metric belongs to (provider's calendar)
- `provider_freshness_at` — when the provider last updated this metric
- `collected_at` — when Reach ingested it
- `raw_response_ref` — reference to immutable raw evidence

---

## Ingestion Cursor Strategy

A single `reach_analytics_ingestion_cursors` row per connector/property/stream tracks:
- `last_ingested_date` — most recent fully-ingested date
- `backfill_from_date` — if a bounded backfill is in progress
- `cursor_state` — JSON for provider-specific pagination tokens

Ingestion is always incremental. Backfills are bounded by `SEARCH_CONSOLE_BACKFILL_DAYS` / `CONTENT_ANALYTICS_BACKFILL_DAYS`.

---

## AI Visibility Architecture

Visibility uses the existing Phase 3 AI infrastructure:

```
VisibilityPromptService → VisibilityExecutionService → AiGenerationOrchestrator
                                    ↓
                        reach_ai_visibility_runs
                                    ↓
                        reach_ai_visibility_responses (immutable)
                                    ↓
                        VisibilityParserService
                                    ↓
              reach_ai_visibility_observations + reach_ai_visibility_citations
```

Visibility prompts have a distinct `purpose = 'ai_visibility_monitoring'`. Marketing generation prompts cannot be silently repurposed for monitoring.

---

## Phase 9 Evidence

Phase 8 populates these time series for Phase 9 use:

| Signal | Table | Phase 9 Use |
|--------|-------|-------------|
| Search position trend | `reach_search_metric_facts` | Position decline detection |
| Content engagement trend | `reach_content_metric_facts` | Engagement decline detection |
| Indexing gaps | `reach_sitemap_entries` | Missing-index detection |
| AI visibility coverage | `reach_ai_visibility_observations` | Visibility gap detection |
| Attribution completeness | `reach_attribution_calculation_versions` | Attribution gap detection |

Phase 9 refresh recommendation and refresh queue are NOT implemented in Phase 8.
