# Reach Programme Traceability — Phase 1 through Phase 9

**Date:** 2026-07-15
**Status:** Phase 9 complete (implementation); production readiness pending human acceptance

---

## Phase Summary

| Phase | Title | Key Capabilities | Baseline Tag | Status |
|-------|-------|-----------------|--------------|--------|
| 1 | Product Knowledge Base | Knowledge items, taxonomy, claims, sources | reach-phase-1-complete | Production |
| 2 | Content Management | Content items, versions, briefs, scheduling | reach-phase-2-complete | Production |
| 3 | AI Content Generation | AI orchestration, grounding, validation, approval | reach-phase-3-complete | Production |
| 4 | Publishing Automation | Blog/KB publishing, HMAC delivery, sitemap, SEO/AEO | reach-phase-4-complete | Production |
| 5 | Community Q&A Automation | Intake, classification, AI answering, approval, publishing | reach-phase-5-complete | Production |
| 6 | Video Content Automation | Video ideation, scripting, versioning, publication | reach-phase-6-complete | Production |
| 7 | Omnichannel Distribution | Social, email, WhatsApp, SMS, audience, consent, templates | reach-phase-7-complete | Production |
| 8 | Search Intelligence & Attribution | GSC, GA4, sitemap intelligence, IndexNow, attribution, AI visibility | reach-phase-8-complete | Staging |
| 9 | Content Refresh Intelligence, Attribution Maturity & Readiness | Evidence-based refresh, multi-touch attribution, product readiness | (pending human acceptance) | Implementation complete |

---

## Capability Traceability

### Content Lifecycle

| Capability | Phase | Service | Table |
|-----------|-------|---------|-------|
| Item creation | 2 | ContentItemService | reach_content_items |
| Versioning | 2 | ContentVersionService | reach_content_versions |
| AI generation | 3 | AiGenerationOrchestrator | reach_ai_generation_requests |
| Approval | 3 | ApprovalPolicy | reach_approvals |
| Blog publication | 4 | BlogPublicationService | reach_blog_publication_profiles |
| KB publication | 4 | KbPublicationService | reach_kb_publication_profiles |
| Refresh detection | 4+9 | ContentRefreshDetectionJob | reach_publication_refresh_reviews |
| Refresh recommendation | 9 | RefreshRecommendationService | reach_refresh_recommendations |
| Refresh workflow | 9 | RefreshWorkflowService | reach_refresh_workflows |
| Refresh generation | 9 | RefreshGenerationService | reach_refresh_content_version_links |
| Refresh publication | 9 | RefreshPublicationService | reach_refresh_publication_links |
| Refresh outcome | 9 | RefreshOutcomeService | reach_refresh_outcome_windows/metrics |

### Community Q&A

| Capability | Phase | Service |
|-----------|-------|---------|
| Question intake | 5 | CommunityIntakeService |
| Classification | 5 | QuestionClassificationService |
| AI answering | 5 | OfficialAnswerGenerationService |
| Approval | 5 | ApprovalPolicy |
| Community publication | 4+5 | AicountlyPublicSitePublisher |
| Correction | 5+9 | OfficialAnswerCorrectionService |
| Withdrawal | 5+9 | OfficialAnswerWithdrawalService |

### Distribution

| Capability | Phase | Service |
|-----------|-------|---------|
| Social | 7 | SocialDistributionService |
| Email | 7 | EmailCampaignService |
| WhatsApp | 7 | WhatsAppCampaignService |
| SMS | 7 | SmsCampaignService |
| Consent management | 7 | ConsentService |
| Audience segmentation | 7 | AudienceSegmentService |
| Campaign versioning | 7+9 | CampaignVersionService |

### Intelligence & Attribution

| Capability | Phase | Service |
|-----------|-------|---------|
| Sitemap intelligence | 8 | SitemapVerificationService |
| IndexNow submission | 8 | IndexNowSubmissionService |
| Search metrics ingestion | 8 | SearchMetricIngestionService |
| Content analytics | 8 | ContentMetricIngestionService |
| Attribution touchpoints | 8 | AttributionTouchpointService |
| Attribution conversion | 8 | AttributionConversionService |
| First/last touch model | 8 | AttributionCalculationService |
| Multi-touch models | 9 | AttributionModelService |
| Journey calculations | 9 | AttributionModelService |
| AI visibility monitoring | 8 | VisibilityExecutionService |
| Competitor visibility | 8 | CompetitorService |
| Anomaly detection | 8 | AnomalyDetectionService |
| Evidence packets | 8 | IntelligenceEvidenceService |

### Readiness

| Capability | Phase | Service/Table |
|-----------|-------|--------------|
| Readiness audit runs | 9 | ReadinessAuditRunModel |
| Readiness findings | 9 | ReadinessFindingModel |
| Technical debt | 9 | TechnicalDebtRecordModel |
| Operational checks | 9 | reach_operational_readiness_checks |
| DR test evidence | 9 | DisasterRecoveryService |
| Release acceptance | 9 | ReleaseAcceptanceRecordModel |

---

## Public-Site Capabilities

| Capability | Phase | Endpoint |
|-----------|-------|---------|
| Draft creation | 4 | POST /api/reach/v1/content/drafts |
| Draft update | 4 | PUT /api/reach/v1/content/drafts/{id} |
| Publication | 4 | POST /api/reach/v1/content/{id}/publish |
| Refresh publication | 9 | POST /api/reach/v1/content/{id}/publish (refresh_type field) |
| Schedule | 4 | POST /api/reach/v1/content/{id}/schedule |
| Unpublish | 4 | POST /api/reach/v1/content/{id}/unpublish |
| Community Q&A | 5 | POST /api/reach/v1/community/* |

---

## Programme Governance

| Control | Phase | Implementation |
|---------|-------|---------------|
| HMAC authentication | 4 | HmacSigner + ReachAuth |
| Self-approval prevention | 3 | ApprovalPolicy |
| AI budget enforcement | 3 | AiBudgetService |
| Data masking | All | SecretRedactor in AuditLogger |
| Optimistic concurrency | 6-9 | lock_version fields |
| Immutability | 3,8,9 | No update paths on version/artifact/snapshot/allocation tables |
| Idempotency | 4-9 | Idempotency keys on all publication operations |
| Nonce replay prevention | 5 | NonceStore TTL |
| Audit trail | All | AuditLogger with 47+ event constants |
