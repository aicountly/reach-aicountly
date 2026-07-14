# Phase 6 — Video Content Automation: Implementation Plan

**Prepared:** 2026-07-14
**Repository:** reach-aicountly
**Baseline commit:** `090dee73eb7811da04c670a4e8825944e0e1f911`
**Tag:** `reach-phase-5-complete`

---

## Overview

Phase 6 implements a fully governed video content lifecycle — from grounded AI-assisted ideation through editorial review, asynchronous rendering, and YouTube publication — built on top of the Phase 0–5 infrastructure already delivered in the `reach-aicountly` repository.

**Scope boundary:**
- Backend: CodeIgniter 4.7.3, PostgreSQL
- Frontend: React 19, Vite
- No changes to `aicountly-com` repository
- No Phase 7 social, email, or distribution features

---

## Checkpoint sequence

### CP0 — Scope freeze and architecture documentation

**Commit:** `docs(reach): define Phase 6 video automation scope and architecture`

**Deliverables:**
- `docs/phases/PHASE_6_SCOPE_RECONCILIATION.md` — classified requirement matrix
- `docs/phases/PHASE_6_DISCOVERY_REPORT.md` — existing infrastructure inventory + risk register
- `docs/phases/PHASE_6_IMPLEMENTATION_PLAN.md` — this file
- `docs/architecture/REACH_VIDEO_AUTOMATION.md` — system overview, lifecycle, data flows
- `docs/architecture/REACH_VIDEO_PROVIDER_CONTRACTS.md` — provider interface contracts

**Stop conditions:**
- No application code changes
- All existing tests still pass (Unit: 681, Vitest: 250)

---

### CP1 — Database schema and domain model

**Commit:** `feat(video): add governed video automation schema`

**New migrations (all `2026-07-14-`, sequential from 100106):**

| # | Migration file | Table |
|---|---|---|
| 100106 | `CreateReachVideoIdeas` | `reach_video_ideas` |
| 100107 | `CreateReachVideoIdeaSources` | `reach_video_idea_sources` |
| 100108 | `CreateReachVideoProjects` | `reach_video_projects` |
| 100109 | `CreateReachVideoScripts` | `reach_video_scripts` |
| 100110 | `CreateReachVideoScriptVersions` | `reach_video_script_versions` |
| 100111 | `CreateReachVideoSegments` | `reach_video_segments` |
| 100112 | `CreateReachVideoCaptionTracks` | `reach_video_caption_tracks` |
| 100113 | `CreateReachVideoChapterMarkers` | `reach_video_chapter_markers` |
| 100114 | `CreateReachVideoAssets` | `reach_video_assets` |
| 100115 | `CreateReachVideoRenderProfiles` | `reach_video_render_profiles` |
| 100116 | `CreateReachVideoRenderJobs` | `reach_video_render_jobs` |
| 100117 | `CreateReachVideoRenderAttempts` | `reach_video_render_attempts` |
| 100118 | `CreateReachVideoPublicationProfiles` | `reach_video_publication_profiles` |
| 100119 | `CreateReachVideoProviderEvents` | `reach_video_provider_events` |
| 100120 | `AddVideoPermissions` | Seeds `video.*` permissions |
| 100121 | `ExtendPublicationConnectionsForVideo` | Extend `reach_publication_connections` CHECK constraints |
| 100122 | `ExtendApprovalsForVideoScript` | Extend `reach_approvals.subject_type` CHECK |

**New PHP enums:**
- `VideoIdeaStatus` — `draft`, `ready`, `accepted`, `rejected`, `archived`, `converted`
- `VideoProjectStatus` — full lifecycle (16 states)
- `VideoScriptWorkflowStatus` — `draft`, `in_review`, `approved`, `rejected`, `changes_requested`
- `VideoRenderJobStatus` — `queued`, `reserved`, `rendering`, `rendered`, `failed`, `cancelled`, `dead_letter`
- `VideoPermission` — all 16 video permission constants

**New PHP models (all under `app/Models/Video/`):**
- `VideoIdeaModel`, `VideoIdeaSourceModel`
- `VideoProjectModel`, `VideoScriptModel`
- `VideoScriptVersionModel`, `VideoSegmentModel`
- `VideoCaptionTrackModel`, `VideoChapterMarkerModel`
- `VideoAssetModel`, `VideoRenderProfileModel`
- `VideoRenderJobModel`, `VideoRenderAttemptModel`
- `VideoPublicationProfileModel`, `VideoProviderEventModel`

**New PHP repositories:**
- `VideoIdeaRepository`, `VideoProjectRepository`
- `VideoScriptRepository`, `VideoRenderJobRepository`
- `VideoPublicationRepository`

**New lifecycle validator:**
- `VideoLifecycleValidator` — validates status transitions against the allowed transition map

**New tests:**
- `MigrationLifecycleTest` extended to assert all 17 Phase 6 tables exist after `latest()` and are absent after `regress(0)`
- `VideoIdeaStatusTest` — validates enum values, no duplicates, CHECK constraint alignment
- `VideoProjectStatusTest` — transition map completeness

---

### CP2 — Permissions, audit constants, and provider foundations

**Commit:** `feat(video): add permissions audit and provider foundations`

**Permission registration:**
- 16 new constants in `app/Enums/VideoPermission.php` and `app/Config/Permissions.php`
- Groups: `video`, `video_connections`, `video_operations`, `video_audit`

**Audit constants (add to `AuditLogger`):**
- `VIDEO_IDEA_*` (created, accepted, rejected, converted, scored, duplicate_flagged)
- `VIDEO_PROJECT_*` (created, updated, cancelled, withdrawn)
- `VIDEO_SCRIPT_*` (generated, submitted, approved, rejected, changes_requested)
- `VIDEO_RENDER_*` (queued, started, completed, failed, cancelled, retried, dead_lettered)
- `VIDEO_PUBLISH_*` (queued, started, published, failed, cancelled, retried)
- `VIDEO_CONNECTION_*` (created, revoked, health_checked)
- `VIDEO_ASSET_*` (uploaded, validated, rejected, deleted)
- `VIDEO_PROVIDER_*` (callback_received, callback_verified, callback_replayed_rejected)

**Provider interfaces and implementations:**
- `Libraries/Video/Providers/RenderProviderInterface.php` — contracts: `queue()`, `status()`, `cancel()`, `getCapabilities()`
- `Libraries/Video/Providers/MockRenderProvider.php` — deterministic success/failure simulation; no network calls
- `Libraries/Video/Providers/YouTubePublisherInterface.php` — contracts: `upload()`, `setMetadata()`, `uploadCaption()`, `setThumbnail()`, `getStatus()`, `getReceiptNormalized()`
- `Libraries/Video/Providers/MockYouTubePublisher.php` — returns `yt-mock-{uuid}`; no network calls
- `Libraries/Video/Providers/VideoProviderFactory.php` — returns mock when `CI_ENVIRONMENT=testing` or `VIDEO_RENDER_PROVIDER=mock`

**Security controls:**
- `VideoAssetGuard` — MIME allowlist (`video/mp4`, `image/jpeg`, `image/png`, `image/webp`, `application/json`), extension check, 500 MB max
- `VideoCallbackAuthenticator` — HMAC-SHA256 + 5-minute timestamp tolerance + replay ID check

**New tests:**
- `VideoPermissionTest` — format, uniqueness, cross-consistency with `Permissions.php`
- `MockRenderProviderTest` — all scenario returns
- `MockYouTubePublisherTest` — all scenario returns
- `VideoCallbackAuthenticatorTest` — valid/invalid/replayed callbacks
- `VideoAssetGuardTest` — MIME rejection, size rejection, extension rejection

---

### CP3 — Video ideation workflow

**Commit:** `feat(video): implement grounded video ideation workflow`

**New services:**
- `VideoIdeationService` — create idea, update status, link sources, flag duplicates, convert to project
- `VideoScoringService` — score idea against 5 dimensions: search demand, topic authority, content gap, audience relevance, competitive differentiation

**New controller:**
- `VideoIdeaController` — CRUD + accept/reject/convert actions

**New routes:**
```
GET/POST    v1/video/ideas
GET/PUT     v1/video/ideas/:id
POST        v1/video/ideas/:id/accept
POST        v1/video/ideas/:id/reject
POST        v1/video/ideas/:id/convert
```

**Grounded idea generation:**
- AI generation for ideas delegates to `AiGenerationOrchestrator` via `VideoIdeationService::generateIdeas()`
- Grounding context from `AiGroundingContextBuilder`

**Frontend pages:**
- `VideoIdeaBacklogPage` — filterable, sortable table with score breakdowns
- `VideoScoreBreakdown` — component for the 5-dimension score display
- `VideoDuplicateWarning` — inline warning when similarity score exceeds threshold

**New tests:**
- `VideoIdeaApiTest` — CRUD, accept, reject, convert, 403 without permission
- `VideoScoringServiceTest` — scoring algorithm unit tests
- `VideoIdeationServiceTest` — status transitions, duplicate detection

---

### CP4 — Video script generation

**Commit:** `feat(video): implement governed video script generation`

**OutputSchemaRegistry additions (5 new types):**
- `video_scene_plan` — ordered scene list with visual directions and VO
- `video_caption_pack` — caption source text with speaker labels and timing hints
- `video_chapter_pack` — chapter list with timestamps and titles
- `video_metadata_pack` — YouTube title, description, tags, category, thumbnails
- `video_thumbnail_brief` — thumbnail composition instructions

**New service:**
- `VideoScriptGenerationService` — delegates to `AiGenerationOrchestrator`; creates script record + first version record; sets project status to `script_generating` then `script_draft` on success or `generation_failed` on error

**New controller + routes:**
```
GET     v1/video/projects/:id/script
POST    v1/video/projects/:id/script
POST    v1/video/projects/:id/script/generate
```

**New job:**
- `VideoScriptGenerationJob` — enqueued to `reach_jobs`; runs `VideoScriptGenerationService::generate()`

**Frontend UI:**
- `VideoScriptGenerationPanel` — triggers generation, shows status polling
- `VideoProjectListPage` — project list with status badges

**Test additions:**
- `OutputSchemaRegistryTest::EXPECTED_TYPES` updated (26 → 31)
- `VideoScriptGenerationServiceTest` — AI delegation, status transitions
- `VideoProjectApiTest` — CRUD, generate endpoint

---

### CP5 — Script review, approval, and immutable versions

**Commit:** `feat(video): add script review approval and immutable versions`

**New services:**
- `VideoScriptVersionService` — immutability enforcement; creates new version on submit; no updates allowed after creation
- `VideoWorkflowService` — status transition enforcement; self-approval prevention via `ApprovalPolicy`

**New controller routes:**
```
POST    v1/video/projects/:id/script/submit
POST    v1/video/projects/:id/script/approve
POST    v1/video/projects/:id/script/reject
POST    v1/video/projects/:id/script/request-changes
GET     v1/video/projects/:id/script/versions
GET     v1/video/projects/:id/script/versions/:v
```

**Self-approval prevention:**
- `VideoWorkflowService::approve()` calls `ApprovalPolicy::canApprove()` with `submitter_id` and `approver_id`
- Returns `HTTP 422` with `error: self_approval_forbidden` if violated

**Frontend UI:**
- `VideoScriptEditorPage` — read-only script viewer for the current version; action bar
- `VideoApprovalActions` — permission-gated; disabled after click; requires `video.approve` permission
- `VideoVersionComparison` — side-by-side diff of two version `content_json` trees

**New tests:**
- `VideoScriptVersionImmutabilityTest` — assert attempt to mutate after creation is rejected
- `VideoSelfApprovalPreventionTest` — assert 422 when submitter === approver
- `VideoWorkflowTest` — full state-machine traversal
- `VideoScriptApiTest` — submit/approve/reject/versions endpoints

---

### CP6 — Secure render orchestration

**Commit:** `feat(video): implement secure render orchestration`

**New services:**
- `VideoAssetService` — upload handler; validates MIME + extension + size via `VideoAssetGuard`; stores under tenant-isolated key; SSRF guard via `UrlPolicy` for remote URLs
- `VideoRenderService` — initiates render job; sets `render_queued` on project
- `VideoRenderJobService` — enqueue/reserve/retry/cancel/dead-letter; exponential backoff

**New controllers + routes:**
```
POST     v1/video/projects/:id/assets
GET      v1/video/assets/:id
GET/POST v1/video/render-profiles
GET/PUT/DELETE v1/video/render-profiles/:id
POST     v1/video/projects/:id/render
GET/DELETE v1/video/render-jobs/:id
POST     v1/video/render-jobs/:id/retry
POST     v1/video/render-jobs/:id/cancel
```

**New job:**
- `VideoRenderJob` — enqueued to `reach_jobs`; calls `RenderProviderInterface::queue()`; stores `provider_job_id`; handles backoff via `VideoRenderJobService::markFailed()`

**New tests:**
- `VideoRenderJobServiceTest` — queue, reserve, retry, dead-letter transitions
- `VideoAssetServiceTest` — MIME rejection, size rejection, successful upload
- `VideoRenderIntegrationTest` — full queue → render → rendered cycle with `MockRenderProvider`
- `VideoRenderProfileApiTest` — CRUD with admin permission

---

### CP7 — Configurable production render integration

**Commit:** `feat(video): add configurable production render integration`

**New files:**
- `Libraries/Video/Providers/ProductionRenderProvider.php` — disabled-by-default skeleton implementing `RenderProviderInterface`; throws `ProviderNotConfiguredException` unless `VIDEO_RENDER_PROVIDER=production`
- `VideoProviderCallbackController` — HMAC-verified callback for both render and YouTube events
- `docs/operations/VIDEO_RENDER_PROVIDER_INTEGRATION.md` — integration guide for deployers

**Callback security:**
- `VideoCallbackAuthenticator::verify()` — HMAC-SHA256, `X-Signature` header, 5-minute window, `reach_video_provider_events` replay guard

**Config:**
- `VIDEO_RENDER_PROVIDER` (default: `mock`) — selects `VideoProviderFactory` backend
- `VIDEO_RENDER_HMAC_KEY` — callback signature key (required when `VIDEO_RENDER_PROVIDER != mock`)
- `YOUTUBE_PUBLISHING_ENABLED` (default: `false`) — enables live YouTube; mock when false

**New routes:**
```
POST    v1/video/provider/render-callback
POST    v1/video/provider/youtube-callback
```

**New tests:**
- `VideoProviderCallbackTest` — valid signature, invalid signature, replayed event
- `ProductionRenderProviderTest` — asserts `ProviderNotConfiguredException` without env key

---

### CP8 — Approved YouTube publishing

**Commit:** `feat(video): implement approved YouTube publishing`

**New service:**
- `VideoConnectionService` — create/revoke/health-check YouTube connections; wraps `reach_publication_connections`; redacts OAuth tokens via `SecretRedactor`
- `VideoPublicationService` — publish video to YouTube via `YouTubePublisherInterface`; uses `reach_publication_deployments/attempts/verifications`; idempotency via `reach_publication_idempotency_records`

**New controllers + routes:**
```
GET/POST    v1/video/connections
GET/DELETE  v1/video/connections/:id
GET         v1/video/connections/:id/health
GET/POST    v1/video/projects/:id/publish
POST        v1/video/projects/:id/publish/retry
POST        v1/video/projects/:id/publish/cancel
GET         v1/video/publications
GET         v1/video/operations
GET         v1/video/projects/:id/audit
```

**New job:**
- `VideoPublicationJob` — enqueued to `reach_jobs`; calls `YouTubePublisherInterface::upload()`; stores receipt; handles retry logic

**Token security:**
- OAuth tokens stored using `SecretRedactor`-compatible storage convention
- Never returned in API responses raw; masked to `***` in all outputs

**Frontend UI:**
- `VideoPublicationPage` — metadata form + preflight checklist + publish button
- `VideoPublicationPreflight` — checklist: script approved, render completed, connection healthy, metadata filled
- `YouTubeConnectionCard` — shows connection status, last health-check time, revoke button
- `VideoProviderHealthIndicator` — traffic-light indicator for YouTube connection

**New tests:**
- `VideoConnectionApiTest` — create, health-check, revoke, token masking verified
- `VideoPublicationServiceTest` — idempotency, retry, cancel
- `VideoPublicationApiTest` — publish endpoint, retry, cancel, audit
- `MockYouTubePublisherIntegrationTest` — full publish cycle

---

### CP9 — Complete React video workspace

**Commit:** `feat(video): add end-to-end video automation interface`

**All frontend routes registered in `App.jsx`:**
```
/video                    → VideoOverviewPage
/video/ideas              → VideoIdeaBacklogPage
/video/projects           → VideoProjectListPage
/video/projects/:id       → VideoProjectWorkspacePage (tabs)
/video/render-queue       → VideoRenderQueuePage
/video/publications       → VideoPublicationListPage
/video/connections        → VideoConnectionsPage
/video/operations         → VideoOperationsDashboardPage
```

**All components wired:**
- `VideoStatusBadge`, `VideoScoreBreakdown`, `VideoDuplicateWarning`
- `VideoScriptEditor`, `VideoVersionComparison`
- `VideoApprovalActions`
- `VideoRenderProfileSelector`
- `VideoPublicationPreflight`
- `YouTubeConnectionCard`
- `VideoProviderHealthIndicator`

**Sidebar section** added above community section, gated on `video.read`.

**Frontend tests (all pages and key components):**
- Route protection: unauthenticated → redirect, insufficient permission → 403 page
- Each page: loading / empty / error / retry states
- `VideoApprovalActions` — permission-gated, duplicate-click protected
- `VideoPublicationPreflight` — all conditions visible
- `VideoScriptEditor` — version selection, comparison visible

**Production build must pass:** `npm run build` with zero errors.

---

### CP10 — Complete validation and exit audit

**Commit:** `test(video): complete Phase 6 validation and exit audit`

**Backend test completeness:**
- `vendor/bin/phpunit --testsuite Unit` — all pass; includes all new video unit tests
- `vendor/bin/phpunit --testsuite Feature` — all pass; includes all new video feature tests
- `MigrationLifecycleTest` — `latest()` creates all 17 Phase 6 tables; `regress(0)` removes all of them; `latest()` again restores all

**Frontend test completeness:**
- `npm run test -- --run` — all Vitest pass; ≥ 40 new video tests
- `npm run lint` — zero errors
- `npm run build` — production build passes

**Security audit:**
- Every new route has permission filter
- Every `input()` call validates/sanitizes before use
- All HMAC secrets via env, not hardcoded
- SSRF guard on all outbound URLs
- No OAuth tokens appear in API responses

**Exit audit documentation:**
- `docs/phases/PHASE_6_EXIT_AUDIT.md` — requirement coverage, test counts, security checklist, known limitations, deployment prerequisites

---

## File creation order

The implementation order follows database-first, then services, then controllers, then routes, then frontend — mirroring the established Phase 3/4/5 pattern.

| Layer | Checkpoint |
|---|---|
| Docs | CP0 |
| Migrations + enums + models + repos | CP1 |
| Permissions + audit constants + provider interfaces/mocks | CP2 |
| Ideation services + controllers + idea pages | CP3 |
| Schema extensions + generation service + generation UI | CP4 |
| Version service + workflow service + script editor | CP5 |
| Asset service + render service + render UI | CP6 |
| Callback handler + production skeleton + config | CP7 |
| Connection service + publication service + publication UI | CP8 |
| All remaining frontend pages + sidebar + tests | CP9 |
| Full test run + security audit + exit docs | CP10 |

---

## Architectural constraints (must hold at every checkpoint)

1. No feature branch — all commits directly to `main`
2. No Phase 7 code — social, email, SMS, DLT, audience segmentation are excluded
3. No AI calls without grounding — `VideoScriptGenerationService` always loads knowledge base context
4. No live provider calls in CI — `VideoProviderFactory` returns mock when `CI_ENVIRONMENT=testing`
5. No self-approval — `VideoWorkflowService::approve()` always checks `ApprovalPolicy::canApprove()`
6. No HTTP 200 for creates — all create endpoints return `201 Created`
7. No `json_encode` before model insert — always pass raw arrays to `?json-array` cast fields
8. No `Services::reset(true)` in tests — always use `Services::reset(false)`
9. No `$this->db->tableExists()` in migration tests — always use `tableLiveExists()`
10. No `$response->getStatusCode()` in tests — always use `$response->response()->getStatusCode()`
