# Phase 9 — Scope Reconciliation

**Phase:** 9 — Content Refresh Intelligence, Attribution Maturity and Final Product Readiness
**Date:** 2026-07-15
**Baseline tag:** `reach-phase-8-complete` → `eeb6b9ba6519f53a190ba286d6527d2c9853e83e`
**Public-site baseline:** `aicountly-public-phase-5-complete` → `2860693c7ca74267d7b9a6bc527842a81ffbe307`

---

## Public-Site Baseline Resolution

No Phase 6, 7, or 8 public tags exist. The latest valid public baseline is:

```
aicountly-public-phase-5-complete
Commit: 2860693c7ca74267d7b9a6bc527842a81ffbe307
```

The public-site working tree had 27 uncommitted LF-to-CRLF line-ending normalisation changes with no functional content differences. These were stashed before Phase 9 began.

---

## Requirement Classification

| # | Requirement | Source | Planned Phase | Status Before P9 | P9 Treatment | Affected Repo |
|---|-------------|--------|--------------|-----------------|--------------|---------------|
| 1 | Content refresh evidence normalisation | Gap Matrix / Phase 9 prompt | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 2 | Explainable refresh recommendation engine | Phase 9 prompt §12.2 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 3 | Deterministic scoring + prioritisation | Phase 9 prompt §12.3 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 4 | Governed refresh workflow lifecycle | Phase 9 prompt §12.4 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 5 | Human triage and approval | Phase 9 prompt §12.5 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 6 | AI-assisted refresh generation | Phase 9 prompt §12.6 | 9 | Phase 3 AI reused | **Confirmed requirement** | reach-aicountly |
| 7 | Blog refresh workflow | Phase 9 prompt §12.7 | 4+9 | Phase 4 foundation exists | **Confirmed requirement** | both |
| 8 | Knowledge-base refresh workflow | Phase 9 prompt §12.7 | 4+9 | Phase 4 foundation exists | **Confirmed requirement** | both |
| 9 | Community correction + withdrawal | Phase 9 prompt §12.7 | 5+9 | Phase 5 implemented | **Already implemented** (wire only) | both |
| 10 | Video script versioning + republication | Phase 9 prompt §12.7 | 6+9 | Phase 6 implemented | **Confirmed requirement** (extend) | reach-aicountly |
| 11 | Campaign content refresh (no redispatch) | Phase 9 prompt §12.7 | 7+9 | Phase 7 campaign versions exist | **Confirmed requirement** (extend) | reach-aicountly |
| 12 | Republishing + post-refresh operations | Phase 9 prompt §12.8 | 9 | Phase 4 publisher reused | **Confirmed requirement** | both |
| 13 | Refresh outcome measurement | Phase 9 prompt §12.9 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 14 | Attribution maturity (first/last/assisted) | Phase 9 prompt §12.10 | 8+9 | Phase 8 first/last only | **Confirmed requirement** | reach-aicountly |
| 15 | Ordered touchpoint journey view | Phase 9 prompt §12.10 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 16 | Multi-touch weighted models (approved) | Phase 9 prompt §12.10 | 9 | Missing | **Confirmed requirement** (equal/position/time-decay only) | reach-aicountly |
| 17 | Final cross-phase integration validation | Phase 9 prompt §12.11 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 18 | Final security audit | Phase 9 prompt §12.12 | 9 | Phase 8 audit partial | **Confirmed requirement** | both |
| 19 | Final privacy audit | Phase 9 prompt §12.12 | 9 | Phase 8 audit partial | **Confirmed requirement** | both |
| 20 | Final AI-governance audit | Phase 9 prompt §12.12 | 9 | Phase 8 audit partial | **Confirmed requirement** | both |
| 21 | Migration lifecycle audit | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 22 | Performance and capacity validation | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 23 | Background-job reliability audit | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 24 | Monitoring and alerting | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 25 | Disaster-recovery planning + testing | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 26 | Backup and restore validation | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 27 | Deployment runbooks | Phase 9 prompt §12.12 | 9 | Phase 8 partial | **Confirmed requirement** | reach-aicountly |
| 28 | Rollback runbooks | Phase 9 prompt §12.12 | 9 | Phase 8 partial | **Confirmed requirement** | reach-aicountly |
| 29 | Technical-debt classification | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 30 | Final BRS traceability Phase 1–9 | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 31 | Final release recommendation | Phase 9 prompt §12.12 | 9 | Missing | **Confirmed requirement** | reach-aicountly |
| 32 | Phase 10 | None | N/A | N/A | **Out of scope** — do not implement | — |
| 33 | Autonomous public publication | Phase 9 exclusions §13 | — | — | **Out of scope** | — |
| 34 | CRM / ERP functionality | Phase 9 exclusions §13 | — | — | **Out of scope** | — |
| 35 | Causal attribution without experiment | Phase 9 exclusions §13 | — | — | **Out of scope** | — |
| 36 | Public-site redesign | Phase 9 exclusions §13 | — | — | **Out of scope** | — |

---

## Already Implemented (Phase 1–8) — Reuse Only

| Capability | Phase | Evidence |
|-----------|-------|---------|
| Content versioning | 2 | `ContentVersionService`, `reach_content_versions` |
| AI generation orchestration | 3 | `AiGenerationOrchestrator`, `OutputSchemaRegistry` |
| Approval + self-approval prevention | 3 | `ApprovalPolicy`, `reach_approvals` |
| Blog publishing + HMAC | 4 | `BlogRefreshService`, `AicountlyPublicSitePublisher` |
| KB publishing | 4 | `KnowledgeBaseRefreshService` |
| Sitemap + IndexNow | 4+8 | `SitemapVerificationService`, `IndexNowSubmissionService` |
| Community correction + withdrawal | 5 | `OfficialAnswerCorrectionService`, `OfficialAnswerWithdrawalService` |
| Video versioning + publication | 6 | `VideoScriptVersionService`, `VideoPublicationService` |
| Campaign versioning | 7 | `CampaignVersionService`, `CampaignChannelVariantService` |
| Attribution first/last touch | 8 | `AttributionTouchpointService`, `reach_attribution_touchpoints` |
| AI visibility observations | 8 | `VisibilityExecutionService`, `reach_ai_visibility_observations` |
| Phase 9 evidence contract | 8 | `IntelligenceEvidenceService::getEvidencePacket()` |
| Anomaly detection | 8 | `AnomalyDetectionService` |

---

## Phase 9 Explicit Exclusions

- Phase 10 (any capability)
- Autonomous public content changes
- Silent content deletion
- Causal ROI attribution without approved experiment
- Fictional metrics or revenue allocations
- Competitor scraping violating provider terms
- Production deployment or migration execution
- Creation of `reach-phase-9-complete` tag (human-controlled)
