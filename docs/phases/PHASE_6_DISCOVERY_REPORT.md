# Phase 6 — Video Content Automation: Discovery Report

**Prepared:** 2026-07-14
**Repository:** reach-aicountly
**Baseline commit:** `090dee73eb7811da04c670a4e8825944e0e1f911`
**Tag:** `reach-phase-5-complete`

---

## 1. Existing infrastructure inventory

### 1.1 Database tables confirmed present after `latest()`

Confirmed via `pg_tables` direct queries (bypasses CI4 stale tableExists cache).

| Table | Created by | Notes |
|---|---|---|
| `reach_actors` | `2026-07-12-100065_CreateReachActors.php` | Foreign key target for `created_by`, `submitted_by`, `approved_by` across all Phase 6 tables |
| `reach_jobs` | `2026-07-12-100029_CreateReachJobs.php` | Async job queue; render and publication jobs will be enqueued here |
| `reach_approvals` | `2026-07-03-100023_CreateReachApprovals.php` | Phase 2 approval records; Phase 6 extends `subject_type` CHECK |
| `reach_ai_generation_requests` | Phase 3 | Linked from `generation_request_id` on ideas |
| `reach_ai_generation_runs` | Phase 3 | Linked from run results |
| `reach_ai_generation_artifacts` | Phase 3 | Linked from `generation_artifact_id` on script versions |
| `reach_content_video_details` | `2026-07-12-100054_CreateReachContentTypeDetails.php` | Existing rich video metadata; separate from Phase 6 video workflow tables |
| `reach_publication_connections` | Phase 4 | Extended by CP8 migration to support `youtube` connection type |
| `reach_publication_deployments` | Phase 4 | Reused for YouTube upload deployments |
| `reach_publication_attempts` | Phase 4 | Reused for upload attempt history |
| `reach_publication_verifications` | Phase 4 | Reused for post-upload verification |
| `reach_publication_webhook_events` | Phase 4 | Reused for YouTube callback deduplication |
| `reach_publication_idempotency_records` | Phase 4 | Reused for publish idempotency |
| `reach_settings` | Phase 0 | Queried with `key` column for config values |
| `reach_community_official_answers` | Phase 5 | Model for `reach_video_ideas` duplicate detection |
| `reach_notification_queue` | Phase 2+ | Available for video workflow notifications |
| `reach_content_similarity_records` | Phase 3 | Pattern for `similarity_score` on ideas |

### 1.2 PHP services and libraries confirmed present

| Service / Library | Location | Phase 6 usage |
|---|---|---|
| `AuditLogger` | `app/Libraries/AuditLogger.php` | Record video lifecycle events; add 25+ video audit constants |
| `AiGenerationOrchestrator` | `app/Libraries/Ai/AiGenerationOrchestrator.php` | `VideoScriptGenerationService` delegates script generation to this |
| `AiValidationPipelineService` | `app/Libraries/Ai/AiValidationPipelineService.php` | Runs claim/citation/risk validators; Phase 6 validation runs linked via `validation_run_id` |
| `AiGroundingContextBuilder` | `app/Libraries/Ai/AiGroundingContextBuilder.php` | Provides grounding context for script + idea generation |
| `GroundingSnapshotService` | `app/Libraries/Ai/GroundingSnapshotService.php` | Snapshots grounding data at generation time |
| `OutputSchemaRegistry` | `app/Libraries/Ai/Prompts/OutputSchemaRegistry.php` | 26 types including `video_script`; add 5 more in CP4 |
| `HmacSigner` | `app/Libraries/Security/HmacSigner.php` | Provider callback signature verification |
| `UrlPolicy` | `app/Libraries/Security/UrlPolicy.php` | SSRF protection on outbound asset URLs |
| `HtmlSanitizer` | `app/Libraries/HtmlSanitizer.php` | `purify()` used on script rich-text |
| `SecretRedactor` | `app/Libraries/Security/SecretRedactor.php` | Mask OAuth tokens in logs/audit |
| `JobService` | `app/Libraries/JobService.php` | Enqueue/reserve/retry/dead-letter logic |
| `ApprovalPolicy` | `app/Libraries/ApprovalPolicy.php` | `canApprove()` prevents self-approval |
| `BaseApiController` | `app/Controllers/BaseApiController.php` | All Phase 6 controllers extend this |
| `PublicSitePublisherFactory` | `app/Libraries/Publishing/PublicSitePublisherFactory.php` | Pattern for `VideoProviderFactory` |
| `PublicSitePublisherInterface` | `app/Libraries/Publishing/PublicSitePublisherInterface.php` | Pattern for `RenderProviderInterface` + `YouTubePublisherInterface` |

### 1.3 Enums confirmed present

| Enum | Location | Phase 6 reference |
|---|---|---|
| `CommunityPermission` | `app/Enums/CommunityPermission.php` | Pattern for `VideoPermission` enum |
| `CommunityQuestionStatus` | `app/Enums/CommunityQuestionStatus.php` | Pattern for `VideoIdeaStatus` + `VideoProjectStatus` |
| `ContentWorkflowStatus` | `app/Enums/ContentWorkflowStatus.php` | Pattern for `VideoScriptWorkflowStatus` |

### 1.4 Configuration files confirmed present

| File | Relevance |
|---|---|
| `app/Config/Permissions.php` | Add `VIDEO_*` and `VIDEO_CONNECTIONS_*` constants and group entries |
| `app/Config/Routes.php` | Add `v1/video/*` route group |
| `app/Config/App.php` | `baseURL`, `environment` settings |
| `.env.testing` | `CI_ENVIRONMENT=testing` — governs mock provider selection |

### 1.5 Frontend infrastructure confirmed present

| Component / Hook | Location | Phase 6 usage |
|---|---|---|
| `usePermission` | `web/src/hooks/usePermission.js` | Gate all video pages and action buttons |
| `Sidebar.jsx` | `web/src/components/layout/Sidebar.jsx` | Add `video.*` section above community section |
| `api.js` | `web/src/services/api.js` | Add video API method groups |
| React Router setup | `web/src/App.jsx` | Register all video routes |
| Content list/editor pattern | `web/src/pages/content/` | Reuse for video project list + editor |
| Community page patterns | `web/src/pages/community/` | Reuse for video idea backlog + moderation-style screens |
| `StatusBadge` | `web/src/pages/content/ContentStatusBadge.jsx` | Pattern for `VideoStatusBadge` |

---

## 2. Key gaps identified (new code required)

### 2.1 Database gaps

All 15 new tables (100106–100120) are absent. No Phase 6 video tables exist beyond `reach_content_video_details` (which serves a different concern).

### 2.2 Service layer gaps

No services exist under `app/Libraries/Video/`. All 12 video services are new:

- `VideoIdeationService`, `VideoScoringService`, `VideoProjectService`
- `VideoScriptService`, `VideoScriptVersionService`, `VideoWorkflowService`
- `VideoAssetService`, `VideoRenderService`, `VideoRenderJobService`
- `VideoPublicationService`, `VideoConnectionService`
- `Libraries/Video/Providers/` — entire directory is new

### 2.3 Controller gaps

No controllers exist under `app/Controllers/Api/V1/Video/`. All 9 controllers are new.

### 2.4 Model gaps

No models exist under `app/Models/Video/`. All 14 models are new.

### 2.5 Route gaps

No `v1/video/*` routes are registered. All 35+ routes are new.

### 2.6 Permission gaps

No `video.*` permissions exist in `Permissions.php`. 16 new permission constants are required.

### 2.7 AI schema gaps

`OutputSchemaRegistry` has `video_script` (existing) but lacks:
- `video_scene_plan`
- `video_caption_pack`
- `video_chapter_pack`
- `video_metadata_pack`
- `video_thumbnail_brief`

### 2.8 Frontend gaps

No pages or components exist under `web/src/pages/video/`. All 10+ pages and 10+ components are new.

---

## 3. Risk register

| Risk | Likelihood | Severity | Mitigation |
|---|---|---|---|
| Production render vendor not confirmed | Confirmed | Medium | CP7 disabled skeleton; mock is CI default; document as deployment prerequisite |
| YouTube OAuth token storage mechanism | Possible | High | CP2 assessment; disabled-by-default adapter; mock YouTube is CI default |
| CI4 tableExists cache stale (Phase 5 lesson) | Confirmed | High | Always use `tableLiveExists()` pattern in migration tests |
| Phase 4 publication tables CHECK constraints too narrow | Possible | Medium | CP1 audits existing CHECK constraints; extension migration adds required values |
| `reach_approvals.subject_type` CHECK too narrow | Confirmed | Medium | CP1 adds `video_script` to CHECK via extension migration |
| Migration gap errors (Phase 5 lesson) | Possible | Medium | Sequential numbering confirmed (100106+); nuclear-reset pattern in `MigrationLifecycleTest` |
| Double-encoding of JSONB (Phase 5 lesson) | Possible | Medium | All model casts use `?json-array`; never `json_encode()` before model insert |
| Services::reset(true) breaking autoloader (Phase 5 lesson) | Confirmed | High | All test helpers use `Services::reset(false)` |
| `getStatusCode()` returning null (Phase 5 lesson) | Confirmed | High | All test assertions use `$response->response()->getStatusCode()` |
| Render job idempotency collision | Possible | Medium | `idempotency_key UNIQUE` constraint on `reach_video_render_jobs` |
| SSRF via asset import URLs | Possible | High | `UrlPolicy::validate()` on all external URLs before fetch |

---

## 4. Test infrastructure baseline

| Test suite | Baseline | Location |
|---|---|---|
| PHPUnit Unit | **681 tests, 1927 assertions — all pass** | `server-php/tests/Unit/` |
| PHPUnit Feature | **329 tests — last CI pass** (requires PostgreSQL) | `server-php/tests/Feature/` |
| Vitest | **63 test files, 250 tests — all pass** | `web/src/` |
| PHPUnit test entry | — | `server-php/phpunit.xml.dist` |
| Frontend test entry | — | `web/vite.config.js` |

Phase 6 test additions at CP10 exit: ≥ 50 new PHPUnit tests (Unit + Feature), ≥ 40 new Vitest tests.

---

## 5. Architecture decisions recorded

| Decision | Rationale |
|---|---|
| All 15 new tables under `2026-07-14-100106+` prefix | Sequential after Phase 5 final migration `100105` |
| Reuse `reach_publication_*` tables for YouTube lifecycle | Avoids redundant schema; maintains consistent deployment/attempt/verification pattern |
| `reach_video_script_versions` is immutable (no `updated_at`) | Once a version is created it cannot change; reviewer sees exactly what was submitted |
| Optimistic concurrency lock on `reach_video_projects` (`lock_version`) | Prevents split-brain state transitions without advisory locks |
| Mock providers selected via `CI_ENVIRONMENT=testing` in `VideoProviderFactory` | Deterministic test runs; no live network calls in CI |
| `VIDEO_RENDER_PROVIDER=mock` env key | Allows explicit override in non-test environments without code changes |
| `YOUTUBE_PUBLISHING_ENABLED=false` env key | Allows disabling live YouTube calls; mock publisher is the safe default |
| Provider callback HMAC uses existing `HmacSigner` | Consistent security pattern across Phase 4 and Phase 6 |
| Asset MIME allowlist via PHP `finfo` | Extension spoofing resistance |
| All video routes prefixed `v1/video/` | Clean namespace; no collision with Phase 1–5 routes |
