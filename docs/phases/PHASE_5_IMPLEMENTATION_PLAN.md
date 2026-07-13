# Phase 5 Implementation Plan — Community and Official Q&A Automation

## Objective

Build a production-grade community Q&A automation subsystem for AICOUNTLY Reach. Collect genuine community questions, generate grounded official answers, route through human approval, and publish securely to aicountly.com/community.

## Frozen Baselines

| Repository | Tag | Commit |
|------------|-----|--------|
| reach-aicountly | reach-phase-4-complete | 274173a (tag), HEAD at 2744794 |
| aicountly-com | aicountly-public-phase-4-complete | 505fd7f (tag), HEAD at 08a643b |

Note: Tags point to Phase 4 test commits. HEAD is at the final Phase 4 style/cleanup commit in both repos. This is acceptable; tags exist and the repos are clean.

## Key Findings from Baseline Review

### reach-aicountly
- Last migration: `100089_CreateReachPublicationIdempotencyRecords.php`
- No `community.*` permissions, routes, controllers, or pages exist
- Phase 3 `AiGenerationOrchestrator` is ready for extension
- Phase 4 `AicountlyPublicSitePublisher` pattern is the template for `CommunityPublicSitePublisher`
- `reach_content_items` supports `community_question`/`community_answer` types but has no community-specific tables

### aicountly-com
- Full live community module at `/community/` using PostgreSQL
- Community uses `community_answers.is_official` (INTEGER) — already has a notion of official answers
- Existing `community_answers` table lacks: `ai_assisted`, `human_reviewed`, `reach_answer_uuid`, `approved_at`, `correction_note`, `withdrawn_at`
- Phase 4 HMAC API at `/api/reach/v1/` handles blog/KB — Phase 5 adds `/api/reach/v1/community/`
- No `community_official_identities` table exists

## Architecture Overview

See:
- `docs/architecture/REACH_COMMUNITY_ARCHITECTURE.md`
- `docs/architecture/REACH_OFFICIAL_QA_GOVERNANCE.md`
- `docs/architecture/REACH_COMMUNITY_PUBLISHING_CONTRACT.md`
- `docs/architecture/REACH_COMMUNITY_DATA_MODEL.md`
- `docs/architecture/REACH_COMMUNITY_SECURITY_MODEL.md`

## Checkpoint Sequence

### CP1 — Baseline verification + architecture documents
**Deliverables:** Architecture docs, data model, security model, publishing contract, governance doc.
**Commit:** `docs(reach): define Phase 5 community and official Q&A architecture`

### CP2 — Database schema (migrations 100090–100105)
**Deliverables:** All Reach migrations, aicountly-com schema extension.
**Commit:** `feat(reach): add Phase 5 community and official Q&A schema`

### CP3 — Domain models, repositories, state-machine enums
**Deliverables:** Enums, value objects, CI4 models, repositories for all new tables.
**Commit:** `feat(reach): add community question and official answer domain layer`

### CP4 — Intake, triage, duplicate detection
**Deliverables:** `CommunityQuestionIntakeService`, `CommunityQuestionClassificationService`, `CommunityDuplicateDetectionService`, `CommunityTriageService`.
**Commit:** `feat(reach): implement community intake triage and duplicate detection`

### CP5 — AI generation and grounding
**Deliverables:** `OfficialAnswerGenerationService`, new prompt templates in OutputSchemaRegistry, grounding integration, `OfficialAnswerVersionService`.
**Commit:** `feat(reach): implement grounded official answer generation`

### CP6 — Moderation, review, approval
**Deliverables:** `OfficialAnswerValidationService`, `OfficialAnswerModerationService`, `OfficialAnswerApprovalService`, `OfficialAnswerCorrectionService`, `OfficialAnswerWithdrawalService`.
**Commit:** `feat(reach): add official answer moderation review and approval controls`

### CP7 — Secure publishing connector
**Deliverables:** `CommunityPublicSitePublisher`, `MockCommunityPublisher`, `CommunityPublisherFactory`, `OfficialAnswerPublishingService`, `CommunityPublicationVerificationService`, `CommunityPublicationReconciliationService`.
**Commit:** `feat(reach): add secure community publishing orchestration`

### CP8 — Public-site receiver and storage
**Deliverables (aicountly-com):** `/api/reach/v1/community.php`, `CommunityRepository.php`, DB migration `006_community_reach_integration.pgsql.sql`.
**Commit:** `feat(aicountly-com): add Reach community publishing receiver`

### CP9 — Public community rendering
**Deliverables (aicountly-com):** Extended `community/question.php` with official answer rendering, identity badge, disclosure, correction notice, noindex rules, sitemap eligibility, QAPage structured data update.
**Commit:** `feat(aicountly-com): integrate official Q&A into public community`

### CP10 — Reach APIs and frontend
**Deliverables:** API controllers, routes, permissions config, 10 frontend pages (Community Control Centre).
**Commits:**
- `feat(reach): add Phase 5 community APIs`
- `feat(reach): add Community Control Centre frontend`

### CP11 — Jobs, analytics, observability
**Deliverables:** Job classes, `CommunityAnalyticsService`, `CommunityEngagementIngestionService`, audit event constants, analytics API endpoints.
**Commit:** `feat(reach): add community jobs analytics and observability`

### CP12 — Security and comprehensive tests
**Deliverables:** 30+ unit tests, 25+ feature tests, 20+ frontend tests, 10+ public-site tests, security remediation.
**Commits:**
- `test(reach): complete Phase 5 community and official Q&A coverage`
- `test(public-site): add official community publishing security coverage`

### CP13 — Final audit and cleanup
**Deliverables:** Implementation report, exit audit, acceptance matrix, risk register, deployment plan, secret scan.
**Commits:**
- `docs(reach): complete Phase 5 implementation and exit audit`
- `style(reach): clean Phase 5 implementation artifacts`

## Technology Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Auth for publishing | Reuse Phase 4 HMAC | Same keys, same middleware, no new credentials |
| AI generation | Extend Phase 3 orchestrator | Adds prompt types; no parallel system |
| Approval | Extend reach_approvals | Subject type = official_answer |
| Community tables | Extend existing aicountly-com community | Co-locate official + UGC content |
| Frontend | React, follows publishing/ pattern | Consistent with existing product |
| Job queue | Extend reach_jobs | Same framework, new job types |

## Environment Variables (Phase 5 additions)

```
COMMUNITY_OFFICIAL_IDENTITY_DEFAULT=aicountly-official
COMMUNITY_ANSWER_MAX_BODY_BYTES=65536
COMMUNITY_RISK_HIGH_REQUIRES_PROFESSIONAL_REVIEW=true
REACH_PUB_COMMUNITY_MOCK=
```

No new secrets. Community publishing reuses existing Phase 4 public-site connection credentials.

## Non-Goals (Phase 5)

- No Phase 6 social media distribution
- No campaign optimization or bulk external posting
- No synthetic engagement generation
- No customer account-linked official identities
- No AI self-publishing (drafts only until human approval)
