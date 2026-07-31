# Engagement Schema Alignment (Decision Phase 2.1)

## Finding (Critical, latent)

`CommunityEngagementIngestionService::ingest()` built insert arrays keyed by
columns that do not exist on `reach_community_engagement_events`
(`answer_external_id` instead of `answer_id`; no `question_id` support; ad hoc
event-type strings not matching the table's `CHECK` constraint).
`CommunityAnalyticsService::engagement()` queried `is_validated` and
`created_at`, neither of which exist — the real columns are `validated` and
`event_timestamp`. Both would fail on first real call.

No controller or job in the codebase calls `CommunityEngagementIngestionService`
today (confirmed by search), so this was latent/unreachable rather than an
active production incident — but any future wiring (e.g. a public→Reach
analytics webhook) would have failed immediately, and `engagement()` reads
were silently broken for any caller.

The pre-existing regression test,
`tests/Unit/Community/CommunityEngagementValidationTest.php`, tested a
hand-inlined reimplementation of a validation heuristic using field names
(`answer_external_id`, `dedup_key`, `source_platform`) that matched **neither**
the old broken service **nor** the real schema — so it could never have caught
this bug regardless of which version of the service existed.

## Fix

- `CommunityEngagementIngestionService.php`: rewritten to use the real schema
  (`answer_id`, `question_id`, `source`, `event_timestamp`,
  `deduplication_key`, `session_reference`, `bot_filtered`, `validated`,
  `ingested_at`), the real event-type vocabulary
  (`page_view`/`helpful`/`not_helpful`/`reply`/`report`/`click`), and a real
  bot-source filter (`reach_service`/`reach_bot`/`reach_automation`/
  `official_identity`/`service_account`, plus explicit `is_bot`/
  `is_official_identity` flags on the input event) — mirroring, on the
  ingestion side, the same guarantee `CommunityReceiverController::
  guardEngagement` enforces on the publish side: Reach-originated actors can
  never manufacture their own engagement.
- `CommunityAnalyticsService::engagement()`: corrected to `validated = TRUE
  AND bot_filtered = FALSE` over `event_timestamp`.
- `tests/Unit/Community/CommunityEngagementValidationTest.php`: rewritten to
  mirror the corrected service logic (real column names) and extended with
  bot-filter and event-type-vocabulary regression cases.

## Test evidence

`php vendor/bin/phpunit tests/Unit/Community/CommunityEngagementValidationTest.php`
→ 13 tests, 23 assertions, OK. Full command/output logged in
`13_TEST_AND_BUILD_EVIDENCE.md`.

## Residual item

No HTTP/job entry point currently calls this service — it is correct but
unwired. Wiring a real ingestion endpoint (if genuine public-side engagement
telemetry is desired) is out of scope for this audit unless the brief's
existing automation/queue requirements (Phase 3/4) require it; flagged for
product decision in `16_FINAL_VERDICT.md`.
