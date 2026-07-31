# Automation: Commands, Schedule, Queue Safety

## Findings

1. **G18 (High)** — `CommunityDeploymentModel::listRetryDue()` (scans for
   deployments with `status = 'retrying' AND next_retry_at <= now() AND
   attempt_count < max_attempts`) existed but was never called from anywhere.
   `CommunityDeploymentRetryJob` can only retry one named `deployment_uuid` —
   nothing fanned that job out over the due set. A dead sibling method,
   `listPendingRetries()`, was additionally broken (its `where()` bound
   `'attempt_count <'` to a *compiled SELECT string* rather than a value) and
   unused everywhere; it has been removed rather than fixed, since
   `listRetryDue()` already covers the same intent correctly.
2. **G19 (High)** — The receiver's `/community/changes` endpoint
   (`CommunityReceiverController::listChanges()`) has existed since Phase 1
   remediation, but `CommunityPublisherInterface` never grew a matching
   client method. Reach therefore had no way to learn about public-side
   moderation actions or status changes except by asking about a specific
   answer it already knew to ask about (`getRecord`/`reconcile`) — comments
   in particular have no other Reach-side visibility at all once posted.
3. No scheduling command existed to run any of the existing per-answer
   reconciliation/retry/analytics jobs on a recurring basis — every one of
   them required a human or an ad hoc script to enqueue it.

## Fix

### New client capability

`CommunityPublisherInterface::getChanges(string $sinceIso8601, int $limit =
500): array` — implemented in `CommunityPublicSitePublisher` (GET
`/api/reach/v1/community/changes?since=...&limit=...`) and
`MockCommunityPublisher` (records calls, returns empty sets by default).

### New detection-only sync service

`CommunityChangeSyncService::sync()`:

1. Reads a cursor (`community_sync_last_since`, via the existing generic
   `SettingModel` key-value store — no new table needed) defaulting to
   "24 hours ago" on first run.
2. Calls `getChanges()`, then classifies each answer/comment row with two
   pure, unit-tested functions: `isAnswerDrift()` (public status is
   `unpublished`/`withdrawn`/`removed`/`deleted` while Reach still thinks
   it's fine) and `isCommentDrift()` (public status is
   `removed`/`hidden`/`flagged`, scoped to comments Reach itself posted via
   `thread_facilitator` — a moderated bot comment is a real signal worth
   Reach's attention; a moderated *human* comment is not Reach's business).
3. **Never overwrites Reach's own status columns.** `reach_community_official_answers.status`
   transitions are governed by `OfficialAnswerRepository`'s strict transition
   table; a background sync tick blindly forcing a transition would bypass
   that safety net entirely. Instead every drifted row is written as a
   `AuditLogger::COMMUNITY_SYNC_DRIFT_DETECTED` entry for a human (or the
   existing `CommunityPublicationReconciliationService`, which *does* have a
   principled, tested outcome function) to act on.
4. Advances the cursor to the latest `updated_at` seen (or leaves it alone on
   a failed call, so nothing is silently skipped).

### New retry sweep

`CommunityDeploymentRetrySweepJob` (`reach.community_deployment_retry_sweep`):
calls `listRetryDue()` and enqueues one `CommunityDeploymentRetryJob` per row,
each with `idempotency_key = "community_deploy_retry_{uuid}_{attempt_count}"`.
Re-running the sweep before a prior retry has advanced `attempt_count` is a
guaranteed no-op (unique index on `reach_jobs.idempotency_key`), not a
duplicate publish attempt.

### New schedule command

`php spark community:schedule` (`App\Commands\CommunitySchedule`), intended
for cron every 5 minutes:

| Cadence | Job |
|---------|-----|
| Every tick | `reach.community_deployment_retry_sweep` |
| Every tick | `reach.community_sync_changes` |
| Every 15 minutes | `reach.community_publication_reconciliation` (heavier — full checksum comparison of every published answer) |
| Daily at 02:00 | `reach.community_analytics_reconciliation` for yesterday |

Every enqueue carries an `idempotency_key` derived from the current time
bucket (`Y-m-d-H-i` for per-minute jobs, `Y-m-d-H-<15-min-slot>` for the
reconciliation tick, `Y-m-d` for the daily analytics job). This is a
deliberate improvement over the pre-existing `reach:schedule` command's
hour-gated pattern (`if ($hour === 8) { $svc->enqueue(...) }` with no
idempotency key at all): if that command's cron overlaps or runs more than
once inside the gated hour, it enqueues the job again with no dedup. The new
Community schedule cannot do that — a repeated enqueue with the same
idempotency key inside `JobService::enqueue()` catches the unique-constraint
violation and returns the existing job's ID instead of inserting a duplicate.

**Deliberately excluded from this schedule:** actual agent *dispatch*
(`question_curator` picking a question, `expert_answer_assistant` drafting an
answer, etc., via `CommunityOperationalAgentService::dispatch()`). Dispatch
acts on specific content selected by whatever upstream source approved it —
a human via the new `/community/agents/dispatch` endpoint, or an approved
intake feed — and this schedule tick has no visibility into or business
picking that content itself. Everything scheduled here is infrastructure:
detecting drift and retrying/reconciling records Reach already knows about,
never selecting new content to act on. Fabricating a "daily plan" that
invents candidate questions/answers to draft would risk violating the
brief's "approved-source only" constraint for a service this audit has no
mandate to design.

### Queue safety (re-verified, not rebuilt)

`JobService` already provides everything the brief asks for and none of it
needed to be rebuilt: atomic claim via `SELECT ... FOR UPDATE SKIP LOCKED`,
per-job `lease_expires_at` + `recoverExpiredLeases()`, exponential backoff
(`min(2^attempt * 15s, 3600s)`), automatic dead-letter at `max_attempts`, and
a partial unique index on `idempotency_key`. The new Community jobs
(`CommunityDeploymentRetrySweepJob`, `CommunitySyncChangesJob`) are ordinary
tenants of this existing infrastructure — no bespoke queue logic was added
for Community specifically.

## Test evidence

- `tests/Unit/Community/CommunityChangeSyncServiceTest.php` — 9 tests over
  `isAnswerDrift()`/`isCommentDrift()`.
- Full `tests/Unit` suite (1024 tests) and full `composer test` (1384 tests,
  118 skipped Feature tests requiring a live Postgres test DB unavailable in
  this dev environment) both green after these changes — see
  `13_TEST_AND_BUILD_EVIDENCE.md`.

## Residual items

- `CommunitySchedule` itself has no dedicated test (it is a thin
  `Services::jobService()->enqueue(...)` composition with no branching logic
  worth mocking in isolation beyond what the idempotency-key scheme already
  guarantees at the `JobService` level). Verified by code reading against the
  actual `JobService::enqueue()` signature.
- Actually installing the cron line (`*/5 * * * * php spark
  community:schedule`) is an ops action — see `16_FINAL_VERDICT.md`.
