# Publish-Time Risk Re-Check (Phase 2.2)

## Finding (High)

`OfficialAnswerLifecycleService::approve()` already correctly blocks tier 4
(`Prohibited`) and requires `professional_review`/`compliance_review` approval
type for tiers 2/3 (`Regulatory`/`IndividualAdvice`) — this gate was already
sound at **approval time** (verified, not rewritten).

However, `OfficialAnswerLifecycleService::setRiskTierWithOverride()` can raise
an answer's risk tier **at any time after approval**, including after it
reaches `Approved`/`Scheduled` status, without automatically revoking the
existing approval record or blocking a subsequent publish. Before this fix,
`OfficialAnswerPublishingService::publish()` only re-verified the approval
*checksum* (that the approved content hasn't changed) — it never re-checked
that the *current* risk tier still permits publication under the *existing*
approval record. A tier raised to 2/3/4 after a `standard` approval could
still publish.

## Fix

Added `OfficialAnswerPublishingService::assertPublishableRiskState()`,
called from both `publish()` and `retryDeployment()` immediately after the
existing checksum gate:

- Tier 4 (`Prohibited`) → always blocked, `RuntimeException` +
  `AuditLogger::COMMUNITY_PUBLISHING_RISK_BLOCKED`.
- Tier 2/3 (`Regulatory`/`IndividualAdvice`) → the most recent `outcome =
  'approved'` row in `reach_community_answer_approvals` for the exact
  approved version/checksum must have `approval_type` of
  `professional_review` or `compliance_review`; a `standard` approval (or none
  found) blocks publication.
- `retryDeployment()` treats a risk block as a terminal `dead_letter` outcome
  rather than retrying — a risk-tier increase is a human policy decision, not
  a transient failure.

## Test evidence

New `tests/Unit/Community/CommunityRiskTierTest.php` (13 tests, 38
assertions, `OK`) covers every gating predicate on `CommunityRiskTier`
(`isBlocked`, `requiresProfessionalApproval`, `isAutoPublishable`,
`requiresFreshEvidence`, `fromQuestion` marker precedence,
`toClassification`/`fromClassification` round-trip safety) that previously had
**zero** dedicated test coverage despite driving every publication gate in
the system.

Full `OfficialAnswerPublishingService::publish()` integration coverage
(instantiating the service, which requires a live DB connection for its
Model dependencies) could not be exercised in this environment — this
repo's Feature test suite (`tests/Feature/Community/*`) is DB-gated and
**all 5 assertions in `CommunityDeploymentApiTest` skip** here for the same
reason (no local Postgres configured; confirmed via
`php vendor/bin/phpunit tests/Feature/Community/CommunityDeploymentApiTest.php`
→ `5 tests, 0 assertions, Skipped: 5`). The new gate was verified by:
(a) direct code reading of the call sites and the exact conditions in
`assertPublishableRiskState()`, (b) the enum-level unit tests above proving
the underlying predicates are correct, and (c) `php -l` syntax verification.
Recommend running the Feature suite against a real Postgres instance in CI
before considering this item fully closed — flagged in
`16_FINAL_VERDICT.md`.
