# Phase 8 Implementation Plan

**Phase:** 8  
**Title:** Search Intelligence, Attribution and AI Visibility  
**Roadmap capabilities:** 29, 30, 31, 32, 33, 34  
**Branch:** main (direct)  
**Baseline:** `reach-phase-7-complete` → `ea09688c527cb6f7f2df78753c5198abd8ed10e8`

---

## Checkpoint Summary

| CP | Title | Key Deliverables | Commit Message |
|----|-------|-----------------|----------------|
| CP0 | Baseline and scope freeze | 7 architecture docs, scope reconciliation | `docs(reach): define Phase 8 intelligence architecture and scope` |
| CP1 | Schema foundation | 28 migrations (100144–100171), models, identity/cursor services | `feat(intelligence): add canonical content and analytics schema` |
| CP2 | Permissions, audits, connector contracts | Permission groups, audit constants, connector interfaces, mocks | `feat(intelligence): add permissions audit and connector contracts` |
| CP3 | Sitemap intelligence and IndexNow | SitemapSnapshotService, IndexNowSubmissionService, 2 controllers, 2 frontend pages | `feat(search): implement sitemap intelligence and IndexNow operations` |
| CP4 | Search Console connector | SearchConsoleService, ingestion job, GSC frontend | `feat(search): add Search Console analytics ingestion` |
| CP5 | Per-content analytics | ContentAnalyticsService extending GA4, ingestion job, content analytics frontend | `feat(analytics): implement per-content performance intelligence` |
| CP6 | UTM and attribution | UtmTemplateService, AttributionTouchpointService, first/last touch, attribution frontend | `feat(attribution): add governed lead-attribution foundations` |
| CP7 | AI visibility | VisibilityPromptService, VisibilityExecutionService, AI jobs, visibility frontend | `feat(visibility): implement governed AI visibility monitoring` |
| CP8 | Competitor monitoring | CompetitorService, CompetitorObservationService, competitor frontend | `feat(visibility): add competitor visibility observations` |
| CP9 | Operations and reconciliation | ConnectorHealthService, AnomalyDetectionService, reconciliation jobs, ops frontend | `feat(intelligence): add aggregation health and reconciliation controls` |
| CP10 | Intelligence Control Centre | IntelligenceLayout, 13+ pages, sidebar, Phase 9 evidence contract | `feat(intelligence): add Phase 8 intelligence control centre` |
| CP11 | Full validation and exit audit | PHPUnit + migration + frontend + audit + exit docs | `test(intelligence): complete Phase 8 validation and exit audit` |

---

## Migration Sequence (100144–100171)

```
100144 CreateReachContentIdentities
100145 CreateReachContentPublicationMappings
100146 CreateReachSitemapSnapshots
100147 CreateReachSitemapEntries
100148 CreateReachIndexNowSubmissions
100149 CreateReachIndexNowAttempts
100150 CreateReachAnalyticsConnections
100151 CreateReachAnalyticsIngestionCursors
100152 CreateReachSearchMetricFacts
100153 CreateReachContentMetricFacts
100154 CreateReachAnalyticsIngestionRuns
100155 CreateReachContentMappingFindings
100156 CreateReachUtmTemplates
100157 CreateReachAttributionTouchpoints
100158 CreateReachAttributionConversionLinks
100159 CreateReachAttributionCalculationVersions
100160 CreateReachAiVisibilityPrompts
100161 CreateReachAiVisibilityPromptVersions
100162 CreateReachAiVisibilityRuns
100163 CreateReachAiVisibilityResponses
100164 CreateReachAiVisibilityObservations
100165 CreateReachAiVisibilityCitations
100166 CreateReachCompetitors
100167 CreateReachCompetitorAliases
100168 CreateReachCompetitorObservationAggregates
100169 CreateReachConnectorHealth
100170 CreateReachMetricFreshness
100171 AddIntelligencePermissions
```

---

## Service Architecture

```
server-php/app/Libraries/Intelligence/
├── Connectors/
│   ├── SearchConsoleConnectorInterface.php
│   ├── ContentAnalyticsConnectorInterface.php
│   ├── IndexNowConnectorInterface.php
│   ├── MockSearchConsoleConnector.php
│   ├── MockContentAnalyticsConnector.php
│   ├── MockIndexNowConnector.php
│   ├── ConnectorProviderFactory.php
│   └── DTOs/ (IngestionRequest, IngestionResult, MetricBatch, ConnectorCursor)
├── ContentIdentityService.php
├── IngestionCursorService.php
├── FreshnessService.php
├── SitemapSnapshotService.php
├── IndexNowSubmissionService.php
├── SearchConsoleService.php
├── ContentAnalyticsService.php
├── UtmTemplateService.php
├── AttributionTouchpointService.php
├── AttributionCalculationService.php
├── VisibilityPromptService.php
├── VisibilityExecutionService.php
├── CompetitorService.php
├── CompetitorObservationService.php
├── ConnectorHealthService.php
├── MetricFreshnessService.php
├── AnomalyDetectionService.php
├── ReconciliationService.php
└── IntelligenceJobTypes.php
```

---

## API Route Families

```
/v1/intelligence/identity/*          (ContentIdentityController)
/v1/intelligence/sitemap/*           (SitemapController)
/v1/intelligence/indexnow/*          (IndexNowController)
/v1/intelligence/search/*            (SearchConsoleController)
/v1/intelligence/content/*           (ContentAnalyticsController)
/v1/intelligence/attribution/*       (AttributionController)
/v1/intelligence/utm-templates/*     (UtmTemplateController)
/v1/intelligence/visibility/*        (VisibilityController)
/v1/intelligence/competitors/*       (CompetitorController)
/v1/intelligence/operations/*        (IntelligenceOperationsController)
/v1/intelligence/connectors/*        (ConnectorController)
```

---

## Frontend Route Family

```
/intelligence                         IntelligenceOverviewPage
/intelligence/search                  SearchIntelligencePage
/intelligence/search/pages            SearchPagePerformancePage
/intelligence/search/queries          SearchQueryPerformancePage
/intelligence/content                 ContentPerformancePage
/intelligence/content/:id             ContentDetailAnalyticsPage
/intelligence/sitemaps                SitemapOverviewPage
/intelligence/indexnow                IndexNowOperationsPage
/intelligence/attribution             AttributionOverviewPage
/intelligence/visibility              VisibilityOverviewPage
/intelligence/visibility/prompts      VisibilityPromptLibraryPage
/intelligence/visibility/runs         VisibilityRunHistoryPage
/intelligence/competitors             CompetitorListPage
/intelligence/connectors              ConnectorConfigPage
/intelligence/operations              IntelligenceOperationsPage
```

---

## Phase 9 Non-Implementation Statement

Phase 8 does NOT implement:
- Refresh recommendations
- Refresh queues
- Automatic content regeneration
- Advanced multi-touch attribution weighting
- Revenue attribution without verified evidence
- Programme-readiness audit
- Autonomous optimisation

Phase 8 DOES prepare Phase 9 evidence contracts (see `REACH_PHASE_9_EVIDENCE_FOUNDATIONS.md`).
