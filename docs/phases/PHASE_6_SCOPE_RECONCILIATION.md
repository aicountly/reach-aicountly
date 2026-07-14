# Phase 6 — Video Content Automation: Scope Reconciliation

**Prepared:** 2026-07-14
**Repository:** reach-aicountly
**Baseline commit:** `090dee73eb7811da04c670a4e8825944e0e1f911`
**Tag:** `reach-phase-5-complete`
**Branch:** `main`

---

## Classification legend

| Label | Meaning |
|---|---|
| **Confirmed requirement** | In scope, traceable to Phase 6 spec and approved roadmap |
| **Inferred requirement** | Not explicitly stated but required to deliver a confirmed requirement safely |
| **Deferred requirement** | Explicitly identified for a later phase |
| **Already implemented** | Delivered in Phase 0–5; Phase 6 reuses without reimplementation |
| **Out of scope** | Excluded by Phase 6 spec; implementing would violate constraints |
| **Superseded requirement** | Replaced by a more specific later requirement |
| **Blocked requirement** | Depends on an external decision not yet made; documented as prerequisite |

---

## Section 1 — Core video lifecycle capabilities

| ID | Requirement | Classification | Evidence |
|---|---|---|---|
| V01 | Grounded video ideation | **Confirmed requirement** | Phase 6 spec §3 item 1; `REACH_PROPOSED_PHASE_PLAN.md` Phase 6 tasks 1–3 |
| V02 | Topic scoring and prioritisation | **Confirmed requirement** | Phase 6 spec §3 item 2; gap matrix capability 10 |
| V03 | Duplicate and overlap detection | **Confirmed requirement** | Phase 6 spec §3 item 3; Phase 5 pattern in `CommunityDuplicateDetectionService` |
| V04 | Video project management | **Confirmed requirement** | Phase 6 spec §3 item 4; `REACH_PROPOSED_PHASE_PLAN.md` task 1 |
| V05 | AI-assisted script generation | **Confirmed requirement** | Phase 6 spec §3 item 5; Phase 3 AI orchestrator already exists |
| V06 | AI-assisted scene, caption, chapter generation | **Confirmed requirement** | Phase 6 spec §3 item 6 |
| V07 | Immutable script versions | **Confirmed requirement** | Phase 6 spec §3 item 7; Pattern from `reach_community_answer_versions` |
| V08 | Editorial review and approval | **Confirmed requirement** | Phase 6 spec §3 item 8; `reach_approvals` + `ApprovalPolicy` already exist |
| V09 | Self-approval prevention | **Confirmed requirement** | Phase 6 spec §3 item 9; `ApprovalPolicy` already enforces this |
| V10 | Claim, citation, risk validation | **Confirmed requirement** | Phase 6 spec §3 item 10; Phase 3 validators exist |
| V11 | Video asset management | **Confirmed requirement** | Phase 6 spec §3 item 11 |
| V12 | Render profiles | **Confirmed requirement** | Phase 6 spec §3 item 12 |
| V13 | Render-provider abstraction | **Confirmed requirement** | Phase 6 spec §3 item 13; Pattern from `PublicSitePublisherInterface` |
| V14 | Deterministic mock render provider | **Confirmed requirement** | Phase 6 spec §3 item 14; CI constraint |
| V15 | Configurable real render-provider integration | **Blocked requirement** | Phase 6 spec §3 item 15; No vendor approved — skeleton only (see §4) |
| V16 | Asynchronous render jobs | **Confirmed requirement** | Phase 6 spec §3 item 16; `reach_jobs` queue exists |
| V17 | Retry, cancellation, dead-letter handling | **Confirmed requirement** | Phase 6 spec §3 item 17; `JobService` patterns exist |
| V18 | Secure provider callbacks | **Confirmed requirement** | Phase 6 spec §3 item 18; HMAC pattern from Phase 4 |
| V19 | YouTube connection and health status | **Confirmed requirement** | Phase 6 spec §3 item 19 |
| V20 | YouTube upload and publication | **Confirmed requirement** | Phase 6 spec §3 item 20; `REACH_PROPOSED_PHASE_PLAN.md` task 5 |
| V21 | Idempotency and replay protection | **Confirmed requirement** | Phase 6 spec §3 item 21; `reach_publication_idempotency_records` reusable |
| V22 | Publication verification and reconciliation | **Confirmed requirement** | Phase 6 spec §3 item 22 |
| V23 | Operational dashboards and audit history | **Confirmed requirement** | Phase 6 spec §3 item 23 |
| V24 | Permission-aware React admin pages | **Confirmed requirement** | Phase 6 spec §3 item 24; `usePermission` hook exists |
| V25 | PostgreSQL migrations | **Confirmed requirement** | Phase 6 spec §3 item 25 |
| V26 | Migration lifecycle tests | **Confirmed requirement** | Phase 6 spec §3 item 25; `MigrationLifecycleTest` pattern exists |
| V27 | Unit, Feature, frontend, integration tests | **Confirmed requirement** | Phase 6 spec §3 item 26 |
| V28 | Operations, deployment, rollback docs | **Confirmed requirement** | Phase 6 spec §3 item 27 |

---

## Section 2 — Dependencies on Phase 0–5 infrastructure

| ID | Capability | Classification | Evidence |
|---|---|---|---|
| D01 | Job queue (`reach_jobs`) | **Already implemented** | `2026-07-12-100029_CreateReachJobs.php`; `JobService`, `JobHandlerRegistry` |
| D02 | Approval system (`reach_approvals`) | **Already implemented** | `2026-07-03-100023_CreateReachApprovals.php`; `ApprovalPolicy` |
| D03 | Self-approval prevention | **Already implemented** | `ApprovalPolicy::canApprove()`; Phase 5 community approval |
| D04 | AI generation orchestrator | **Already implemented** | `AiGenerationOrchestrator`, `AiGenerationRequestService`, Phase 3 |
| D05 | AI output schema registry | **Already implemented** | `OutputSchemaRegistry` with 26 types including `video_script` |
| D06 | `video_script` schema type | **Already implemented** | One of the 26 existing types — Phase 6 *extends* it |
| D07 | AI validation pipeline | **Already implemented** | `AiValidationPipelineService`, 22 validators |
| D08 | Grounding service | **Already implemented** | `AiGroundingContextBuilder`, `GroundingSnapshotService` |
| D09 | Audit logger | **Already implemented** | `AuditLogger` with 100+ event constants |
| D10 | HMAC signing | **Already implemented** | `HmacSigner`; Phase 4 publishing security |
| D11 | Secret redactor | **Already implemented** | `SecretRedactor` |
| D12 | URL policy / SSRF protection | **Already implemented** | `UrlPolicy` |
| D13 | HTML sanitiser | **Already implemented** | `HtmlSanitizer::purify()` |
| D14 | Publication connections table | **Already implemented** | `reach_publication_connections` — requires extension migration for `oauth2` and `video` |
| D15 | Publication deployments/attempts/verifications | **Already implemented** | `reach_publication_deployments/attempts/verifications` — requires extension migration for YouTube operations |
| D16 | Webhook event deduplication | **Already implemented** | `reach_publication_webhook_events` |
| D17 | Idempotency records | **Already implemented** | `reach_publication_idempotency_records` |
| D18 | `reach_content_video_details` | **Already implemented** | Created by `2026-07-12-100054_CreateReachContentTypeDetails.php`; Phase 6 video workflow tables are additive |
| D19 | Permission system | **Already implemented** | `Permissions.php` with `groups()`, `all()`, `isKnown()` |
| D20 | `usePermission` hook | **Already implemented** | `web/src/hooks/usePermission.js` |
| D21 | Mock publisher factory pattern | **Already implemented** | `PublicSitePublisherFactory` — Phase 6 mock providers follow same convention |
| D22 | `BaseApiController` | **Already implemented** | `ok()`, `fail()`, `input()`, `pagination()`, `audit()`, `userId()` |
| D23 | `reach_actors` table | **Already implemented** | `2026-07-12-100065_CreateReachActors.php` |
| D24 | Content similarity records | **Already implemented** | `reach_content_similarity_records` for duplicate detection |
| D25 | Rate limiting | **Already implemented** | `RequestIdFilter`, `reach_rate_limits` |
| D26 | `reach_notification_*` tables | **Already implemented** | Available for video workflow notifications |

---

## Section 3 — Out-of-scope capabilities

| ID | Capability | Classification | Reason |
|---|---|---|---|
| OS01 | LinkedIn publishing | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS02 | X (Twitter) publishing | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS03 | Facebook publishing | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS04 | Instagram publishing | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS05 | Email dispatch | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS06 | WhatsApp dispatch | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS07 | SMS dispatch | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS08 | DLT workflow | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS09 | Audience segmentation | **Out of scope** | Phase 6 spec §out-of-scope; Phase 7 |
| OS10 | GSC ingestion | **Out of scope** | Phase 6 spec §out-of-scope; Phase 8 |
| OS11 | AI visibility monitoring | **Out of scope** | Phase 6 spec §out-of-scope; Phase 8 |
| OS12 | Competitor monitoring | **Out of scope** | Phase 6 spec §out-of-scope; Phase 8 |
| OS13 | Performance-triggered content refresh | **Out of scope** | Phase 6 spec §out-of-scope; Phase 9 |
| OS14 | Attribution modelling | **Out of scope** | Phase 6 spec §out-of-scope; Phase 9 |
| OS15 | Public-site redesign | **Out of scope** | Phase 6 spec §out-of-scope |
| OS16 | Automatic publication without human approval | **Out of scope** | Phase 6 spec §out-of-scope; ethical constraint |
| OS17 | Destructive remote YouTube content deletion | **Out of scope** | Phase 6 spec §out-of-scope |
| OS18 | Phase 7 provider implementations | **Out of scope** | Phase 6 spec §out-of-scope |
| OS19 | `aicountly-com` code changes | **Out of scope** | No material public-site change required |

---

## Section 4 — Blocked requirements

| ID | Requirement | Blocker | Resolution path |
|---|---|---|---|
| BL01 | Production render provider | No vendor approved in repository documents or planning docs | CP7: implement disabled skeleton + integration guide; mock is CI default; mark as deployment prerequisite |
| BL02 | YouTube OAuth 2.0 encrypted token storage | No existing encrypted-credential store confirmed | CP2: assess existing mechanism; if absent, implement disabled connection adapter; document as deployment prerequisite |

---

## Section 5 — Inferred requirements

| ID | Requirement | Inferred from | Checkpoint |
|---|---|---|---|
| I01 | Extension migration for `reach_publication_connections` (add `oauth2` auth type, `video` content type) | YouTube OAuth 2.0 requirement | CP1/CP8 |
| I02 | Extension migration for `reach_publication_deployments` (add YouTube-specific operations to CHECK) | YouTube publication lifecycle | CP1/CP8 |
| I03 | `VideoProviderEnum` / `VideoStatusEnum` | Status-machine enforcement requires defined enum values | CP1 |
| I04 | `VideoWorkflowService` with explicit transition map | No business logic in controllers | CP1–CP5 |
| I05 | `tableLiveExists()` pattern in Phase 6 migration tests | Phase 5 lesson: CI4 `tableExists()` cache returns stale results | CP1 |
| I06 | `OutputSchemaRegistryTest::EXPECTED_TYPES` update (26 → 31) | Adding 5 new video schema types | CP4 |
| I07 | `VideoProviderFactory` checking `CI_ENVIRONMENT=testing` | Prevent live calls during automated tests | CP2 |
| I08 | `video` content type added to `reach_approvals.subject_type` CHECK | Script approval uses `reach_approvals` | CP1 |

---

## Section 6 — Phase 7 preparation (in scope)

The following contracts arise naturally from Phase 6 and prepare Phase 7 without implementing Phase 7 features:

| Contract | Purpose | Files |
|---|---|---|
| `RenderProviderInterface` | Capability declaration pattern for any future media provider | `Libraries/Video/Providers/RenderProviderInterface.php` |
| `YouTubePublisherInterface` | Publication receipt normalisation | `Libraries/Video/Providers/YouTubePublisherInterface.php` |
| `VideoProviderFactory` | Registry/factory pattern for provider selection | `Libraries/Video/Providers/VideoProviderFactory.php` |
| Provider connection health contract | Outbound connection health reporting | `VideoConnectionService` |
| Webhook authentication contract | Callback signature + replay window | `VideoProviderCallbackController` |
| Idempotency convention | Tenant-scoped idempotency keys | `reach_publication_idempotency_records` reuse |

**No social, email, WhatsApp, or SMS provider implementations are included.**

---

## Section 7 — Roadmap conflict analysis

| Conflict | Assessment |
|---|---|
| Phase 5 delivered 16 community migrations (100090–100105); Phase 6 starts at 100106 | No conflict — sequential ordering confirmed |
| `video_script` schema already exists in `OutputSchemaRegistry` | No conflict — Phase 6 extends the schema fields, does not recreate the type |
| `reach_content_video_details` exists from Phase 2 | No conflict — Phase 6 video workflow tables are additive and separate concerns |
| Phase 4 `reach_publication_connections` used for blog/KB | Phase 6 extends via migration (`authentication_type`, `supported_content_types`) — same table, wider contract |
| Phase 2 `reach_approvals` used for content approval | Phase 6 extends `subject_type` CHECK to include `video_script` — backward compatible |

---

## Summary

- **27 confirmed requirements** fully deliverable within Phase 6
- **26 already-implemented dependencies** from Phase 0–5 that Phase 6 reuses
- **2 blocked requirements** (production render vendor, YouTube token storage) documented as deployment prerequisites
- **8 inferred requirements** (technical necessities not explicitly stated)
- **19 out-of-scope capabilities** explicitly excluded
- **6 Phase 7 preparation contracts** prepared without implementing Phase 7 features
- **0 roadmap conflicts** identified
- **Public-site impact: none** — no `aicountly-com` changes required
