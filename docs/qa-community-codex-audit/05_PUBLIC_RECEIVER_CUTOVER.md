# Public Receiver Cutover (Decision 1A)

## Finding (Critical)

`aicountly-com` shipped two independent implementations of the Reach→Public
Community Q&A receiver:

1. **`api/reach/v1/community.php`** (legacy, actually wired into
   `api/reach/v1/index.php`): authenticated with `ReachAuth` against
   `REACH_SERVICE_TOKEN`/`REACH_SIGNING_KEY`; implemented only a subset of
   operations; question creation was a stub that never persisted a
   `public_question_id` back to Reach.
2. **`includes/publisher/CommunityReceiverController.php`** (fuller, but not
   wired into any route): authenticates with `CommunityReceiverAuth` against
   `AICOUNTLY_PUBLIC_SITE_*` credentials — the same credentials Reach's real
   outbound client, `CommunityPublicSitePublisher`, actually signs with — and
   implements the full contract (identities, questions, answers, comments,
   corrections, moderation, changes, reconcile).

Net effect: the credentials Reach was actually sending could never
authenticate against the endpoint that was actually live. Any real publish
attempt from Reach would have failed authentication end-to-end.

## Decision (locked by user: 1A)

Wire `CommunityReceiverController` as the one live HTTP path for
`/api/reach/v1/community/*`. Retire the legacy stack rather than maintaining
two business-logic implementations.

## Fix

- `aicountly-com/api/reach/v1/index.php`: added a `community` segment branch
  that loads `includes/publisher/bootstrap.php` and dispatches directly to
  `CommunityReceiverController`, **before** the legacy `includes/reach/*`
  requires. This ordering is required because both stacks declare same-named
  global classes (`NonceStore`, `HtmlSanitizer`) with different
  implementations — a single PHP process can only load one definition, so the
  two stacks are mutually exclusive per-request rather than both-loaded.
- `api/reach/v1/community.php`: commented out in full and replaced with a
  deprecation notice documenting why it's retained on disk (historical
  reference) and explicitly warning not to re-wire without re-auditing.
- `database/migrations/008_community_receiver_schema.pgsql.sql`: additive
  migration closing every column/table gap between migration `006` and what
  `CommunityReceiverController` actually reads/writes (see Gap Register G2).

## Residual risk

`COMMUNITY_REACH_RECEIVER_ENABLED` kill-switch env var mentioned in the plan
was not added as a separate flag — the controller is unconditionally live once
routed, matching "default-enable when wired" from the plan. If an operational
kill switch is desired, add an env check around the `dispatch()` call in
`index.php` before production rollout (ops-side action, tracked in
`16_FINAL_VERDICT.md`).
