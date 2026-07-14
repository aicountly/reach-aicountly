# Reach Video Automation — System Architecture

**Prepared:** 2026-07-14
**Repository:** reach-aicountly
**Phase:** 6

---

## 1. Overview

The Reach Video Automation system delivers a fully governed video content lifecycle within the AICOUNTLY Reach platform. Starting from AI-grounded idea discovery, through editorial script review and approval, asynchronous render orchestration, and YouTube publication — every transition is:

- **Permission-gated** — enforced at the route filter level
- **Audit-trailed** — every state change written to the audit log
- **Human-in-the-loop** — no AI output reaches YouTube without human approval
- **Idempotent** — render and publication jobs are safe to retry without side effects
- **Tenant-isolated** — every query and storage key is scoped to the authenticated tenant

---

## 2. System context

```
┌──────────────────────────────────────────────────────────────┐
│  React 19 Admin UI                                           │
│  /video/*  pages (CP9)                                       │
└─────────────────────────┬────────────────────────────────────┘
                          │ JWT-authenticated API calls
                          ▼
┌──────────────────────────────────────────────────────────────┐
│  CodeIgniter 4 API  (v1/video/* routes)                      │
│                                                              │
│  VideoIdeaController   VideoScriptController                 │
│  VideoProjectController VideoAssetController                 │
│  VideoRenderController  VideoPublicationController           │
│  VideoConnectionController                                   │
│  VideoProviderCallbackController  (HMAC-verified, unauthed)  │
└──────┬───────────────────────────┬───────────────────────────┘
       │                           │
       ▼                           ▼
┌──────────────┐         ┌─────────────────────────┐
│ Service layer│         │ Phase 0–5 shared libs    │
│ Video/       │         │ AiGenerationOrchestrator │
│              │         │ OutputSchemaRegistry     │
│ Ideation     │         │ AuditLogger              │
│ Scoring      │         │ ApprovalPolicy           │
│ Project      │         │ JobService               │
│ Script       │         │ HmacSigner               │
│ ScriptVersion│         │ UrlPolicy                │
│ Workflow     │         │ SecretRedactor           │
│ Asset        │         │ HtmlSanitizer            │
│ Render       │         └─────────────────────────┘
│ RenderJob    │
│ Publication  │
│ Connection   │
└──────┬───────┘
       │
       ▼
┌──────────────────────────────────────────────────────────────┐
│  PostgreSQL database                                         │
│                                                              │
│  Video tables (Phase 6 new):                                 │
│  reach_video_ideas          reach_video_idea_sources         │
│  reach_video_projects       reach_video_scripts              │
│  reach_video_script_versions reach_video_segments            │
│  reach_video_caption_tracks reach_video_chapter_markers      │
│  reach_video_assets         reach_video_render_profiles      │
│  reach_video_render_jobs    reach_video_render_attempts      │
│  reach_video_publication_profiles                            │
│  reach_video_provider_events                                 │
│                                                              │
│  Reused Phase 0–5 tables:                                    │
│  reach_jobs                 reach_approvals                  │
│  reach_actors               reach_ai_generation_*            │
│  reach_publication_connections   reach_publication_deployments│
│  reach_publication_attempts      reach_publication_verifications│
│  reach_publication_webhook_events reach_publication_idempotency_records│
└──────────────────────────────────────────────────────────────┘
       │                           │
       ▼                           ▼
┌──────────────────┐   ┌───────────────────────────┐
│  MockRenderProvider│  │  MockYouTubePublisher      │
│  (CI default)    │   │  (CI default)              │
│                  │   │                            │
│  ProductionRender│   │  YouTubePublisher          │
│  Provider        │   │  (disabled by default,     │
│  (skeleton only, │   │   requires YOUTUBE_        │
│   disabled)      │   │   PUBLISHING_ENABLED=true) │
└──────────────────┘   └───────────────────────────┘
```

---

## 3. Video lifecycle state machines

### 3.1 Video idea states

```
draft ──→ ready ──→ accepted ──→ (converted to project)
                 ╰──→ rejected
          ready ──→ archived
draft ──→ archived
```

| State | Description |
|---|---|
| `draft` | Idea created but not yet scored or reviewed |
| `ready` | Scored and ready for editorial accept/reject decision |
| `accepted` | Accepted; can be converted to a video project |
| `rejected` | Rejected by editorial; no further action |
| `archived` | Manually archived |
| `converted` | Converted to a video project; read-only |

### 3.2 Video project states

```
draft
 └→ script_generating ─→ script_draft ─→ script_in_review
                      ╰→ generation_failed (can retry generate)
    script_draft ────────────────────────────────────────→ script_in_review
    script_in_review ──→ script_approved ─→ render_queued
                     ├→ changes_requested ──→ script_draft
                     └→ script_rejected (project stalls; restart)
    render_queued ──→ rendering ──→ rendered ──→ publish_queued
                              ╰→ render_failed ──→ render_queued (retry)
    publish_queued ──→ publishing ──→ published
                               ╰→ publish_failed ──→ publish_queued (retry)

    Any state ──→ cancelled (terminal)
    Any state ──→ withdrawn (terminal, by original creator)
    render_queued/rendering ──→ validation_blocked (compliance hold)
```

### 3.3 Script workflow within a project

```
(script created by AI or manually)
 └→ draft
     └→ in_review (submitted for approval)
         ├→ approved (script locked; project → render_queued)
         ├→ changes_requested (back to draft; new version on next edit)
         └→ rejected (terminal for this script)
```

**Immutability rule:** A `reach_video_script_versions` record is written once. Every new submit creates a new version row. The `is_current` flag on the previous version is set to `false`. The version's `content_json` is never updated after creation.

### 3.4 Render job states

```
queued ──→ reserved ──→ rendering ──→ rendered (terminal success)
                               ╰→ failed ──→ queued (retry, backoff)
                                        ╰→ dead_letter (max_attempts reached, terminal)
queued/reserved/rendering ──→ cancelled (terminal)
```

---

## 4. Data flows

### 4.1 Idea generation flow

```
1. User requests idea generation → VideoIdeaController::generate()
2. VideoIdeationService loads grounding context via AiGroundingContextBuilder
3. Delegates to AiGenerationOrchestrator with 'video_idea_list' schema
4. Orchestrator calls AI provider; response parsed against schema
5. Each idea inserted into reach_video_ideas (status=draft)
6. VideoScoringService scores each idea (5 dimensions)
7. Scoring result written back to score_total / score_breakdown
8. Ideas with similarity_score > threshold get duplicate_of_id set
9. Status set to 'ready'; AuditLogger::record(VIDEO_IDEA_CREATED)
```

### 4.2 Script generation flow

```
1. User triggers generate → VideoScriptController::generate()
2. VideoScriptService verifies project status = draft|script_draft|generation_failed
3. Project status → script_generating; VideoScriptGenerationJob enqueued to reach_jobs
4. Job dequeued by worker:
   a. VideoScriptGenerationService loads grounding + project context
   b. Delegates to AiGenerationOrchestrator with 'video_script' schema
   c. Response validated by AiValidationPipelineService (claims, citations, risk)
   d. VideoScriptModel::create() + VideoScriptVersionModel::create() (version=1)
   e. Project status → script_draft
   f. AuditLogger::record(VIDEO_SCRIPT_GENERATED)
5. On error: project status → generation_failed; AuditLogger::record(VIDEO_SCRIPT_GENERATION_FAILED)
```

### 4.3 Approval flow

```
1. Author submits for review → VideoScriptController::submit()
2. VideoWorkflowService::submit():
   a. Verifies project status = script_draft
   b. Creates new reach_video_script_versions row (immutable)
   c. Sets is_current = true on new version; false on previous
   d. Project status → script_in_review
   e. AuditLogger::record(VIDEO_SCRIPT_SUBMITTED, submitted_by=userId)
3. Reviewer approves → VideoScriptController::approve()
4. VideoWorkflowService::approve():
   a. Loads ApprovalPolicy; calls canApprove(submitter_id, approver_id)
   b. If same actor → HTTP 422 self_approval_forbidden
   c. Sets approved_by, approved_at on script version
   d. Sets approved_script_version_id on project
   e. Project status → script_approved
   f. AuditLogger::record(VIDEO_SCRIPT_APPROVED, approved_by=userId)
```

### 4.4 Render flow

```
1. User queues render → VideoRenderController::queue()
2. VideoRenderService verifies project status = script_approved
3. VideoRenderJobService::enqueue():
   a. Generates idempotency_key = "{project_id}:{approved_version_id}:{profile_id}"
   b. INSERT reach_video_render_jobs (conflict ON idempotency_key → return existing)
   c. Project status → render_queued
   d. VideoRenderJob enqueued to reach_jobs
4. VideoRenderJob dequeued:
   a. VideoRenderJobService::reserve() — status → reserved
   b. RenderProviderInterface::queue() called
   c. provider_job_id stored; status → rendering
   d. INSERT reach_video_render_attempts (attempt record)
5. Provider callback received → VideoProviderCallbackController::renderCallback()
   a. VideoCallbackAuthenticator::verify() — HMAC + timestamp + replay guard
   b. VideoRenderJobService::complete() or ::markFailed()
   c. On complete: INSERT reach_video_assets; project status → rendered
   d. On failure: exponential backoff; status → failed; if attempts >= max → dead_letter
```

### 4.5 YouTube publication flow

```
1. User queues publication → VideoPublicationController::publish()
2. VideoPublicationService::queue():
   a. Verifies project status = rendered
   b. Verifies reach_video_publication_profiles record exists
   c. Checks YouTubeConnectionService::isConnectionHealthy()
   d. Generates idempotency_key = "{project_id}:{video_asset_id}:youtube"
   e. INSERT reach_publication_deployments (reuse Phase 4 table)
   f. VideoPublicationJob enqueued to reach_jobs
   g. Project status → publish_queued
3. VideoPublicationJob dequeued:
   a. INSERT reach_publication_attempts
   b. YouTubePublisherInterface::upload() called
   c. Receipt stored in reach_publication_deployments.provider_ref
   d. YouTubePublisherInterface::setMetadata() called
   e. INSERT reach_publication_verifications
   f. Project status → published
   g. AuditLogger::record(VIDEO_PUBLISH_PUBLISHED)
4. On failure: exponential backoff; retry enqueued; INSERT reach_publication_attempts
```

---

## 5. Security model

### 5.1 Permission matrix

| Action | Required permission |
|---|---|
| List/get ideas, projects, scripts | `video.read` |
| Create idea / project | `video.create` |
| Edit idea / project metadata | `video.update` |
| Trigger AI generation | `video.generate` |
| Submit script for review | `video.submit` |
| Approve or reject script | `video.approve` |
| Queue render | `video.render` |
| Publish to YouTube | `video.publish` |
| Cancel render or publication | `video.cancel` |
| Retry render or publication | `video.retry` |
| Manage YouTube connections | `video_connections.manage` |
| Read connection status / health | `video_connections.read` |
| Read operations dashboard | `video_operations.read` |
| Read audit timeline | `video_audit.read` |

### 5.2 Object-level tenant checks

Every controller action verifies `tenant_id` before returning or mutating data:

```php
$idea = $this->ideaRepo->findByUuid($uuid);
if ($idea === null || (int)$idea['tenant_id'] !== $this->tenantId()) {
    return $this->fail('Not found', 404);
}
```

### 5.3 SSRF guard

All externally-supplied URLs (remote asset imports, thumbnail URLs, provider webhook origins) are validated against `UrlPolicy` before any outbound connection:

```php
if (! $this->urlPolicy->validate($remoteUrl)) {
    throw new SecurityException('URL not in allowlist');
}
```

### 5.4 Asset security

- MIME type validated with `finfo_open(FILEINFO_MIME_TYPE)`; extension cross-checked
- Max upload size: 500 MB enforced before stream copy
- Storage key: `video/{tenant_id}/{uuid}.{ext}` — no user-controlled path segments
- No executable MIME types: `application/x-executable`, `text/html`, etc. are rejected

### 5.5 Provider callback security

```
POST v1/video/provider/render-callback
Header: X-Signature: sha256={hex}
Header: X-Timestamp: {unix_epoch}

VideoCallbackAuthenticator::verify():
1. |now - timestamp| <= 300 seconds (replay window)
2. Recompute HMAC-SHA256(body, VIDEO_RENDER_HMAC_KEY)
3. timing-safe compare with X-Signature header
4. SELECT reach_video_provider_events WHERE provider_event_id = {id}
   → if exists: HTTP 409 replay rejected
5. INSERT reach_video_provider_events (deduplicate)
```

### 5.6 OAuth token handling

YouTube OAuth tokens are stored using `SecretRedactor`-compatible conventions:
- Never returned raw in API responses (masked to `***`)
- Redacted from all audit log context payloads
- Logging entries contain token fingerprint (first 8 hex chars of SHA-256), not the token

---

## 6. Database relationships

```
reach_video_ideas
  ├── reach_video_idea_sources (1:many)
  ├── created_by → reach_actors
  └── duplicate_of_id → reach_video_ideas (self-ref)

reach_video_projects
  ├── idea_id → reach_video_ideas
  ├── approved_script_version_id → reach_video_script_versions
  └── created_by → reach_actors

reach_video_scripts
  └── project_id → reach_video_projects

reach_video_script_versions
  ├── script_id → reach_video_scripts
  ├── generation_artifact_id → reach_ai_generation_artifacts
  ├── approved_by → reach_actors
  ├── submitted_by → reach_actors
  └── created_by → reach_actors

reach_video_segments
  └── script_version_id → reach_video_script_versions

reach_video_caption_tracks
  └── script_version_id → reach_video_script_versions

reach_video_chapter_markers
  └── script_version_id → reach_video_script_versions

reach_video_assets
  └── project_id → reach_video_projects

reach_video_render_jobs
  ├── project_id → reach_video_projects
  ├── script_version_id → reach_video_script_versions
  ├── render_profile_id → reach_video_render_profiles
  ├── output_asset_id → reach_video_assets
  └── created_by → reach_actors

reach_video_render_attempts
  └── render_job_id → reach_video_render_jobs

reach_video_publication_profiles
  └── project_id → reach_video_projects

reach_video_provider_events
  (standalone dedup table; no FK to specific entity)

reach_publication_connections [reused]
  (YouTube connection records added by Phase 6 CP8)

reach_publication_deployments [reused]
  (YouTube upload records added by Phase 6 CP8)
```

---

## 7. Configuration reference

| Environment variable | Default | Description |
|---|---|---|
| `VIDEO_RENDER_PROVIDER` | `mock` | Render backend: `mock` or `production` |
| `VIDEO_RENDER_HMAC_KEY` | — | HMAC key for render provider callbacks (required when `production`) |
| `YOUTUBE_PUBLISHING_ENABLED` | `false` | Enable live YouTube publishing (mock when false) |
| `YOUTUBE_CLIENT_ID` | — | YouTube OAuth 2.0 client ID |
| `YOUTUBE_CLIENT_SECRET` | — | YouTube OAuth 2.0 client secret (encrypted at rest) |
| `VIDEO_ASSET_MAX_BYTES` | `524288000` | Max asset upload size in bytes (500 MB) |
| `VIDEO_CALLBACK_TIMESTAMP_TOLERANCE` | `300` | Callback replay window in seconds |

---

## 8. Deployment prerequisites

The following are **not delivered** by Phase 6 implementation but are required for production operation:

| Prerequisite | Phase 6 status | Notes |
|---|---|---|
| Production render vendor selection | Not implemented | Skeleton adapter in CP7; requires vendor decision |
| `VIDEO_RENDER_HMAC_KEY` provisioning | Not implemented | Must be set in production `.env` |
| `YOUTUBE_PUBLISHING_ENABLED=true` | Not set by default | Requires YouTube app approval + credentials |
| YouTube OAuth 2.0 application credentials | Not implemented | Requires Google Cloud Console setup |
| Asset storage backend (S3 / GCS / local) | Reuses existing file-handling convention | Must be configured for production scale |

---

## 9. Extension points (Phase 7+)

Phase 6 establishes the following contracts that Phase 7 can extend without modifying Phase 6 code:

| Extension point | How Phase 7 uses it |
|---|---|
| `RenderProviderInterface` | Register a new provider class in `VideoProviderFactory` |
| `YouTubePublisherInterface` | Implement a `LinkedInPublisher`, `FacebookPublisher`, etc. |
| `VideoProviderFactory` | Add new provider slug → class mappings |
| `reach_video_provider_events` | Generalised event dedup for any future provider callbacks |
| `VIDEO_RENDER_PROVIDER` env key | Zero-code provider swap without application changes |
| `reach_publication_connections.connection_type` | Add new social platform connection types |
