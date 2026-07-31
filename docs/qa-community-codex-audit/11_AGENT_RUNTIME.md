# Role-Routed Agent Runtime (Decision 2A)

## Finding (High)

No agent runtime existed. Five official-identity operational roles were
defined in the schema (`operational_role` on
`reach_community_official_identities`, seeded by migration `200002`) but
nothing dispatched behaviour based on that column — `expert_answer_assistant`
worked only because `OfficialAnswerLifecycleService`/`OfficialAnswerGenerationService`
already existed independently of the role concept; the other four roles
(`question_curator`, `community_steward`, `thread_facilitator`,
`review_objection_desk`) had no runtime behaviour at all — `thread_facilitator`
in particular had no way to ever post a comment, since no Reach-side comment
capability existed anywhere.

## Decision (locked by user: 2A)

One role-routed dispatcher, not five duplicate modules.

## Fix

`CommunityOperationalAgentService` is the single entry point
(`dispatch($identitySlug, $action, $context, $actorId)`). It:

1. Resolves the identity → `operational_role`.
2. Rejects any action not in that role's `ROLE_ACTIONS` set (a static map,
   unit-tested to have zero cross-role overlap).
3. Blocks content-creating actions (`curate_question`, `draft_answer`,
   `post_comment`) outside the **Asia/Kolkata 09:00–19:00** automation window
   (`CommunityAutomationWindow`, pure/injectable-clock, unit tested against
   boundary instants). Pure review/analysis actions are not window-gated —
   they never create new public content.
4. Enforces the plan's seed daily caps (4 curated questions, 4 drafted
   answers, ≤5 comments per identity per day) by counting successful rows in
   the new `reach_community_agent_runs` table.
5. Delegates to the role's collaborator, then records **every** attempt
   (success, blocked, or failed) to `reach_community_agent_runs` — satisfying
   "record on each run: identity, role, style profile, prompt version,
   provider, model, run ID, timestamp" (run ID = `run_uuid`, defaulted by the
   database).

### Per-role collaborators

| Role | Collaborator | Behaviour |
|------|--------------|-----------|
| `question_curator` | `CommunityQuestionCurationService` (new) | Wraps the existing intake→classify→triage→dedupe pipeline (previously only reachable via `CommunityQuestionIntakeJob`, with no identity attribution) and records a `selection_reason`. **Scope note:** sourcing candidates from "approved external sources" is a data-integration/product decision this audit does not fabricate a scraper for — this service starts once a candidate arrives via the existing `import`/`content_request` source types. |
| `expert_answer_assistant` | `OfficialAnswerLifecycleService` (existing, reused) | `createDraft` → `requestGeneration`, unchanged — this role already worked correctly; the dispatcher just gives it uniform caps/logging. |
| `community_steward` | `CommunityStewardService` (new) | Category/tag assignment, related-question linking (reuses `CommunityDuplicateDetectionService::findCandidates()` at a looser threshold than exact-duplicate detection, into a new `reach_community_question_related_links` table), and moderation-hint flagging. **Has no vote/like/follow/view-count method at all** — enforced structurally, not just by a runtime check, and regression-tested via reflection (`CommunityStewardServiceGuardrailTest`). |
| `thread_facilitator` | `CommunityThreadFacilitationService` (new) | Posts a comment via `CommunityPublisherInterface::createComment()` (added to the interface earlier in this audit). Enforces: no self-reply (checked against the identity's own run history), max depth 3, 10-minute cooldown between comments — independent of and in addition to the dispatcher's daily cap. |
| `review_objection_desk` | `CommunityReviewObjectionService` (new) | Flags unsupported claims/conflicts and recommends risk escalation or revision **into `reach_community_moderation_findings`** — a human-reviewed queue. Deliberately does *not* call `OfficialAnswerLifecycleService::requestChanges()`/`setRiskTierWithOverride()` directly: those methods require a real `reach_users` actor ID for legal/compliance accountability, and this audit will not fabricate human attribution for a bot-initiated decision. The bot recommends; a human decides. |

## Test evidence

- `tests/Unit/Community/CommunityAutomationWindowTest.php` — 7 tests covering
  open/closed boundaries and timezone-offset independence.
- `tests/Unit/Community/CommunityOperationalAgentServiceTest.php` — 7 tests:
  per-role action sets, zero cross-role overlap, no engagement-inflation
  action name exists under any role, window-gating classification, seed cap
  values.
- `tests/Unit/Community/CommunityStewardServiceGuardrailTest.php` — 2 tests:
  reflection-based proof that `CommunityStewardService` exposes exactly
  `categorize`/`linkRelated`/`flagModerationHint` and nothing
  vote/like/follow/inflate-shaped.
- Full `tests/Unit/Community` suite: 158 tests, 1269 assertions, `OK`.

Full dispatch-to-database integration (actually calling `dispatch()` against a
live identity row and asserting the `reach_community_agent_runs` write) needs
a real Postgres connection, unavailable in this dev environment (see
`09_PUBLISH_RISK_GATE.md` for the same constraint on the publish-gate tests).
Verified by code reading against the actual model/service signatures instead.

## Residual items (tracked in `16_FINAL_VERDICT.md`)

- Approved-source candidate sourcing for `question_curator` (which external
  feeds are "approved") is a product/ops configuration decision, not a code
  gap.
- `CommunityThreadFacilitationService`'s depth is caller-supplied rather than
  computed from the actual public-side comment tree — the receiver itself
  independently validates parent/depth server-side, so this is defense-in-depth,
  not the sole enforcement point.
