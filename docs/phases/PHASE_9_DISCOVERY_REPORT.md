# Phase 9 — Discovery Report

**Date:** 2026-07-15
**Baseline:** `reach-phase-8-complete` @ `eeb6b9ba6519f53a190ba286d6527d2c9853e83e`

---

## Existing Infrastructure Phase 9 Reuses

### Refresh Foundations (Phase 4)
- `server-php/app/Libraries/Publishing/Blog/BlogRefreshService.php`
- `server-php/app/Libraries/Publishing/KnowledgeBase/KnowledgeBaseRefreshService.php`
- Migration `100087_CreateReachPublicationRefreshReviews` — existing refresh review table
- `server-php/app/Jobs/ContentRefreshDetectionJob.php` — existing detection job

### Community Correction (Phase 5)
- `server-php/app/Libraries/Community/OfficialAnswerCorrectionService.php`
- `server-php/app/Libraries/Community/OfficialAnswerWithdrawalService.php`

### AI Orchestration (Phase 3)
- `server-php/app/Libraries/Ai/Generation/AiGenerationOrchestrator.php`
- `server-php/app/Libraries/Ai/Prompts/OutputSchemaRegistry.php`
- `server-php/app/Libraries/ApprovalPolicy.php`

### Evidence Contract (Phase 8)
- `server-php/app/Libraries/Intelligence/IntelligenceEvidenceService.php`
  - Entry: `getEvidencePacket(contentIdentityId, asOf, windowDays=28)`
  - Returns: `identity`, `search`, `engagement`, `indexing`, `visibility`, `attribution`, `freshness`, `completeness`
- `server-php/app/Libraries/Intelligence/AnomalyDetectionService.php`

### Publishing Pipeline (Phase 4)
- `server-php/app/Libraries/Publishing/Connector/AicountlyPublicSitePublisher.php`
- `server-php/app/Libraries/Publishing/Connector/HmacSigner.php`
- `server-php/app/Libraries/Publishing/Jobs/PublicationRetryService.php`
- Migration `100089_CreateReachPublicationIdempotencyRecords`

---

## Gaps Requiring Phase 9 New Work

### Missing Tables (22 new migrations, starting 100172)
- Refresh policies and versions
- Refresh evidence snapshots (immutable Phase 8 evidence copy)
- Refresh recommendations + score components
- Refresh workflows (state machine)
- Refresh briefs
- Refresh content version links
- Refresh publication links
- Refresh outcome windows + metrics
- Attribution models and versions
- Attribution journey calculations + allocation facts
- Readiness audit runs + findings
- Technical debt records
- Operational readiness checks
- Disaster recovery test records
- Release acceptance records
- `AddRefreshPermissions` migration

### Missing Services
- `RefreshPolicyService` — policy definitions and versioning
- `RefreshEvidenceService` — evidence packet reader + snapshot writer
- `RefreshRecommendationService` — scoring, dedup, cooldown, supersession
- `RefreshWorkflowService` — state machine, optimistic concurrency
- `RefreshBriefService` — brief creation with evidence attachment
- `RefreshGenerationService` — AI generation via Phase 3 orchestrator
- `RefreshPublicationService` — publication queue and delivery
- `RefreshOutcomeService` — post-refresh observed-change measurement
- `AttributionModelService` — equal/position/time-decay models
- `ReadinessAuditService` — cross-phase readiness checks
- `TechnicalDebtService` — debt recording and classification
- `DisasterRecoveryService` — DR test evidence recording

### Missing Frontend
- `/readiness` route family (13 pages) with `ReadinessLayout`
- Sidebar "Product Readiness" section

### Missing Documentation
- Architecture: `REACH_CONTENT_REFRESH_INTELLIGENCE.md`, `REACH_PHASE_9_DATA_MODEL.md`, `REACH_ATTRIBUTION_MATURITY.md`, `REACH_FINAL_PRODUCT_READINESS.md`, `REACH_PHASE_9_WORKFLOW_GOVERNANCE.md`, `REACH_REFRESH_EVIDENCE_AND_POLICIES.md`, `REACH_REFRESH_RECOMMENDATION_ENGINE.md`, `REACH_REFRESH_EDITORIAL_WORKFLOW.md`, `REACH_REFRESH_PUBLICATION_INTEGRATION.md`, `REACH_REFRESH_OUTCOMES.md`
- Operations: 8 new runbooks
- Audit: 9 final audit documents

---

## Migration Sequence Overview

Current latest migration: `100171_AddIntelligencePermissions`
Phase 9 range: `100172–100193` (22 migrations)

---

## Baseline Test Results

| Suite | Result |
|-------|--------|
| PHPUnit Unit | 832 tests, 2357 assertions — OK |
| PHPUnit Feature | 359 tests, 1219 assertions, 116 skipped — OK |
| npm lint | Clean (0 errors) |
| npm test | 71 files, 271 tests — OK |
| npm build | 1802 modules — OK |

---

## Public-Site Baseline

Tag: `aicountly-public-phase-5-complete` @ `2860693c7ca74267d7b9a6bc527842a81ffbe307`

No Phase 6, 7, or 8 public tags exist. The public repository HEAD matches the baseline tag.

27 uncommitted files (LF line-ending normalisation only — no functional changes) were stashed before Phase 9.

Phase 9 will require controlled changes to the public-site publishing receiver to support `refresh_type` field.
