# Phase 9 — Implementation Plan

**Phase:** 9 — Content Refresh Intelligence, Attribution Maturity and Final Product Readiness
**Baseline:** `reach-phase-8-complete` @ `eeb6b9ba6519f53a190ba286d6527d2c9853e83e`

---

## Checkpoint Deliverables

| CP | Title | Key Deliverables | Commit |
|----|-------|-----------------|--------|
| CP0 | Baseline, discovery, scope freeze | 7 architecture docs, scope reconciliation, baseline tests | `docs(reach): define Phase 9 refresh and readiness architecture` |
| CP1 | Refresh and readiness schema | 23 migrations (100172–100194), models, enums | `feat(refresh): add Phase 9 refresh and readiness schema` |
| CP2 | Permissions, audits, workflow contracts | `Permissions.php`, `AuditLogger.php`, `RefreshWorkflowTransitions.php` | `feat(refresh): add workflow permissions and audit governance` |
| CP3 | Evidence normalisation + policy engine | `RefreshPolicyService`, `RefreshEvidenceService`, policy frontend | `feat(refresh): implement evidence normalisation and refresh policies` |
| CP4 | Recommendation + prioritisation engine | `RefreshRecommendationService`, extend `ContentRefreshDetectionJob` | `feat(refresh): add explainable refresh recommendations` |
| CP5 | Triage, briefs, generation, approval | `RefreshWorkflowService`, `RefreshBriefService`, `RefreshGenerationService` | `feat(refresh): implement governed refresh generation and approval` |
| CP6 | Content-type integrations + republication | Extend Blog/KB/Community/Video/Campaign, aicountly-com receiver | `feat(refresh): integrate refresh workflow with publishing` |
| CP7 | Outcome measurement + attribution maturity | `RefreshOutcomeService`, `AttributionModelService` | `feat(attribution): add refresh outcomes and attribution maturity` |
| CP8 | Performance, monitoring, operational controls | Indexes, `RefreshOperationsService`, operations frontend | `perf(reach): harden Phase 1-9 operations and monitoring` |
| CP9 | Security, privacy, AI governance, DR | Final audits, `DisasterRecoveryService`, 5 audit docs | `security(reach): complete final governance and recovery hardening` |
| CP10 | Readiness control centre + docs | `ReadinessLayout`, 13 frontend pages, 8 runbooks | `feat(readiness): add final product-readiness control centre` |
| CP11 | Final programme audit + handoff | Full test suites, 14 exit docs, Phase 1–9 traceability | `test(readiness): complete Phase 9 and final programme audit` |

---

## Migration Sequence

```
100172  CreateReachRefreshPolicies
100173  CreateReachRefreshPolicyVersions
100174  CreateReachRefreshEvidenceSnapshots
100175  CreateReachRefreshRecommendations
100176  CreateReachRefreshScoreComponents
100177  CreateReachRefreshWorkflows
100178  CreateReachRefreshBriefs
100179  CreateReachRefreshContentVersionLinks
100180  CreateReachRefreshPublicationLinks
100181  CreateReachRefreshOutcomeWindows
100182  CreateReachRefreshOutcomeMetrics
100183  CreateReachAttributionModels
100184  CreateReachAttributionModelVersions
100185  CreateReachAttributionJourneyCalculations
100186  CreateReachAttributionAllocationFacts
100187  CreateReachReadinessAuditRuns
100188  CreateReachReadinessFindings
100189  CreateReachTechnicalDebtRecords
100190  CreateReachOperationalReadinessChecks
100191  CreateReachDisasterRecoveryTests
100192  CreateReachReleaseAcceptanceRecords
100193  AddRefreshPermissions
```

---

## Service Architecture

```
RefreshPolicyService
  └─ reads: reach_refresh_policies, reach_refresh_policy_versions
  └─ writes: reach_refresh_policies, reach_refresh_policy_versions

RefreshEvidenceService
  └─ calls: IntelligenceEvidenceService::getEvidencePacket()
  └─ writes: reach_refresh_evidence_snapshots (immutable)

RefreshRecommendationService
  └─ reads: reach_refresh_evidence_snapshots, reach_refresh_policies
  └─ reads: AnomalyDetectionService results
  └─ writes: reach_refresh_recommendations, reach_refresh_score_components

RefreshWorkflowService
  └─ reads: reach_refresh_workflows
  └─ orchestrates: detected → recommended → triaged → accepted → approved → published
  └─ uses: ApprovalPolicy (self-approval prevention)

RefreshGenerationService
  └─ calls: AiGenerationOrchestrator (Phase 3)
  └─ writes: refresh content versions (immutable after approval)

RefreshPublicationService
  └─ calls: BlogRefreshService / KBRefreshService / OfficialAnswerCorrectionService
  └─ calls: AicountlyPublicSitePublisher (HMAC)
  └─ writes: reach_refresh_publication_links
  └─ triggers: SitemapSnapshotService, IndexNowSubmissionService

RefreshOutcomeService
  └─ calls: IntelligenceEvidenceService (baseline + post periods)
  └─ writes: reach_refresh_outcome_windows, reach_refresh_outcome_metrics
  └─ uses "Observed post-refresh change" language, never "caused by"

AttributionModelService
  └─ reads: reach_attribution_touchpoints, reach_attribution_conversion_links
  └─ models: equal_weight, position_based, time_decay
  └─ writes: reach_attribution_journey_calculations, reach_attribution_allocation_facts
```

---

## Frontend Route Family

```
/readiness                    ReadinessOverviewPage
/readiness/refresh            RecommendationBacklogPage
/readiness/refresh/:id        RefreshWorkspacePage
/readiness/outcomes           RefreshOutcomePage
/readiness/attribution        AttributionMaturityPage
/readiness/security           SecurityStatusPage
/readiness/privacy            PrivacyStatusPage
/readiness/ai-governance      AiGovernancePage
/readiness/migrations         MigrationStatusPage
/readiness/operations         OperationsDashboardPage
/readiness/disaster-recovery  DisasterRecoveryPage
/readiness/technical-debt     TechnicalDebtPage
/readiness/release            ReleaseAcceptancePage
```
