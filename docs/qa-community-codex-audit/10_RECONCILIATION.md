# Reach↔Public Reconciliation (Phase 2.3)

## Finding (High)

`docs/architecture/REACH_COMMUNITY_ARCHITECTURE.md` claims a
`CommunityPublicationReconciliationService` ("Detect state drift between
Reach and public site") and a `CommunityDeploymentController` reconcile
action. Neither existed. `CommunityPublicationVerificationService` covered
only single-answer, on-demand verification (`verify($answerId)`, called from
`CommunityPublicationVerificationJob` and the `/deployments/{uuid}/verify`
endpoint) — there was no batch mechanism, and Reach's "published" status was
therefore only ever cross-checked at the moment of the original publish call
or when a human manually triggered one verification at a time.

## Fix

- `CommunityPublicationReconciliationService::reconcileAll()` — selects every
  `reach_community_official_answers` row with `status = 'published'` and a
  `public_external_id`, batches UUIDs under the receiver's
  `MAX_RECONCILE_IDS` (200) limit, and calls
  `CommunityPublisherInterface::reconcile()` (added to the interface/both
  implementations earlier in this audit — see `05_PUBLIC_RECEIVER_CUTOVER.md`).
  Every checked/mismatched/missing answer is written to
  `reach_community_answer_verifications` (`verification_outcome`) and failures
  raise `AuditLogger::COMMUNITY_ANSWER_VERIFICATION_FAILED`.
- `CommunityPublicationReconciliationService::determineOutcome()` — the pure
  decision function (published + checksum match → `passed`, otherwise
  `mismatch`) is a separate static method specifically so it is unit
  testable without a database.
- `CommunityPublicationReconciliationJob` (job type
  `reach.community_publication_reconciliation`) registered in
  `JobHandlerRegistry`, ready to be scheduled (wired into the community
  schedule tick in Phase 4).
- `CommunityDeploymentController::reconcile()` +
  `POST /community/deployments/reconcile` route
  (`permission:community_answer.publish`), closing the doc-vs-code gap.

Idempotency: every run is read-then-record; it never mutates the answer or
deployment row, only appends a verification log row, so concurrent/duplicate
runs cannot corrupt state — satisfying "retries must be idempotent".

## Test evidence

`tests/Unit/Community/CommunityPublicationReconciliationServiceTest.php` — 5
tests, 5 assertions, `OK`, covering all four outcome branches (published+match,
wrong status, checksum mismatch, missing fields).

Full end-to-end reconciliation (calling a real `CommunityPublisherInterface`
against a live public site) is not exercised here — verified by code reading
against the actual `CommunityReceiverController::reconcile()` response shape
(`success`, `records[]` with `reach_external_id`/`public_status`/
`payload_checksum`, `missing[]`).
