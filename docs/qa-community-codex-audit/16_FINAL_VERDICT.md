# Final Verdict

## Verdict: COMPLETE WITH EXTERNAL DEPLOYMENT ACTIONS

Opus 5.0's Community Q&A implementation was **not** production-grade at the
start of this audit. It had genuine, well-structured domain logic in places
(the official-answer lifecycle state machine, risk-tier classification, the
receiver's HMAC contract) but three categories of defect made the overall
system non-functional or unsafe as shipped:

1. **A dual-stack public receiver** where Reach's real publisher signed
   requests the actually-live endpoint could never authenticate (G1) —
   the entire Reach↔public integration was non-functional at baseline.
2. **Claimed-but-absent subsystems**: the role-routed agent runtime (G17),
   the reconciliation service (G11), and the change-sync/retry-sweep
   automation (G18, G19) were all described in architecture docs as
   existing and did not exist in code at all.
3. **A real, exploitable stored XSS** (G21) in the public-facing markdown
   renderer — reachable by any community member posting a question or
   answer, no elevated privilege required. This is the single most severe
   finding of the audit and was not hypothetical: `[text](javascript:...)`
   rendered as a live, clickable, script-executing link.

All 21 confirmed gaps (`04_GAP_REGISTER.md`) are now **Fixed** or
**Fixed (regression-tested)**, except the two explicitly marked
**Verified — no gap** (G12, G13 — pre-existing code found sound on
inspection, not defects). Every fix has a corresponding automated test where
the fix is DB-independent pure logic; DB-dependent paths were verified by
direct reading against real model/service signatures where a live Postgres
connection was unavailable in this dev environment (`13_TEST_AND_BUILD_EVIDENCE.md`).

## Why "with external deployment actions" and not unconditional "COMPLETE"

Everything below is an **operations action outside this repository's
control**, not a code gap this audit could close by editing files:

### Required before the fixes in this audit take effect in any environment

1. **Run the public schema migration**: `database/migrations/008_community_receiver_schema.pgsql.sql`
   (and `009_blog_publications_robots_directive.pgsql.sql`, unrelated but
   pending) against the public site's Postgres database.
2. **Run the Reach migrations** added in this audit:
   `2026-07-31-210001_CreateReachCommunityAgentRuns.php`,
   `2026-07-31-210002_CreateReachCommunityQuestionRelatedLinks.php`,
   `2026-07-31-220001_AddCommunityAgentDispatchPermission.php` (plus the
   unrelated blog-content-approval migrations already pending in the working
   tree, out of this audit's scope).
3. **Re-run `RolesAndPermissionsSeeder`** (`php spark db:seed
   RolesAndPermissionsSeeder`) so already-provisioned roles pick up the new
   `community_agent.dispatch` permission — the seeder is idempotent
   (existing rows are updated in place), so this is safe to re-run in any
   environment at any time.
4. **Set `COMMUNITY_REACH_RECEIVER_ENABLED=true`** (and confirm
   `AICOUNTLY_PUBLIC_SITE_BASE_URL` / `_SERVICE_TOKEN` / `_SIGNING_KEY` /
   `_KEY_ID` are set to matching values on both sides) — the receiver fails
   closed with a clear 503 when this flag is absent, by design; enabling it
   is a deliberate go-live action, not something this audit should flip
   silently.
5. **IP allowlist** for the receiver (`community_receiver_allowed_ips()`) —
   populate with Reach's actual outbound IP(s) in production.

### Required to make the new automation actually run

6. **Install the cron line** for the new schedule command:
   ```
   */5 * * * * cd /path/to/server-php && php spark community:schedule
   ```
   (alongside the pre-existing `reach:schedule` cron, unrelated to this audit).
7. **Ensure queue workers are running** against the `community` queue (the
   new jobs enqueue with `'queue' => 'community'`) — this audit adds jobs to
   the existing worker pool, it does not stand up new worker infrastructure.

### Product/ops decisions this audit deliberately did not fabricate

8. **Approved external source(s) for `question_curator`** — the plan asks
   for "approved-source candidate generation" but does not name a concrete
   feed. `CommunityQuestionCurationService` is ready to receive candidates
   the moment a real source is wired to the existing intake path; inventing
   a scraper or heuristic source would be scope creep with real product risk
   (accidental ingestion of non-approved content).
9. **Which role(s) beyond `community_automation_admin`** should hold
   `community_agent.dispatch` in each real deployment's org structure is a
   people/process decision this audit's seeder change proposes a sensible
   default for but does not mandate.

## What would make this "COMPLETE" outright

Running items 1–7 above in a real environment with a live Postgres
connection, then re-running the currently-skipped Feature tests and a live
`dispatch()` → `reach_community_agent_runs` integration check. Nothing in
that list requires further code changes — it requires infrastructure this
audit does not have access to (a provisioned database, cron, and queue
workers in a real deployment target).

## Confidence statement

This audit did not stop at reporting. Every Critical/High finding was
reproduced against the actual code (not assumed from documentation), fixed
in the repository, covered by a new or corrected automated test where the
logic was DB-independent, and re-verified by running the full test suite and
production build for both repositories at the end of every phase — not just
once at the end. The severity distribution (1 Critical security defect, 2
Critical functional defects, 12 High defects, 2 Medium/verified-sound, plus
4 additional High findings surfaced during Phase 4–5 remediation itself)
reflects genuine defects found through reproduction, not inflated or
deflated to hit a target count.
