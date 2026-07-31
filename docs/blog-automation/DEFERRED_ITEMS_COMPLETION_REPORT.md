# Deferred Items — Completion Report

Follow-on pass after `FOUNDATION_COMPLETION_DELIVERABLES.md`. This pass closes the
three items that the foundation phase explicitly deferred:

1. Gemini / Perplexity / image adapters
2. Daily roadmap optimiser scoring
3. Live Search Console connector (previously mock-only)

Auditing for leftovers also surfaced a fourth, unrelated item that was half-built and
non-functional — the Community Q&A receiver on the public site (section 4). It is now
closed too.

Everything ships **off by default**. No feature added here changes runtime behaviour
until its flag is switched on and its credentials are supplied.

---

## 1. AI provider adapters

### Text providers

| Adapter | Key | Endpoint | Notes |
|---|---|---|---|
| `GeminiProvider` | `gemini` | `POST /v1beta/models/{model}:generateContent` | Structured output via `responseSchema` + `responseMimeType`. Health check lists models (no token spend). |
| `PerplexityProvider` | `perplexity` | `POST /chat/completions` | OpenAI-compatible. Citations are surfaced in `parsedJson._citations`. |

Both are registered in `AiProviderRegistry` alongside the existing OpenAI and mock
adapters. `OpenAiProvider` was not modified.

Because Perplexity exposes no free models endpoint, its health check does **not**
spend tokens unless `AI_PERPLEXITY_HEALTHCHECK_ALLOW_SPEND=true`; otherwise it reports
"configured, live check skipped".

### Image providers

A dedicated contract lives in `app/Libraries/Ai/Images/` rather than overloading the
text interface, because image responses carry binary payloads and no token counts.

- `ImageProviderInterface`, `ImageGenerationInput`, `ImageGenerationResult`
- `OpenAiImageProvider` (`openai_image`) — `/v1/images/generations`, reuses `AI_OPENAI_API_KEY`
- `GeminiImageProvider` (`gemini_image`) — inline image parts from `generateContent`
- `ImageProviderRegistry` — same resolve/get/configured semantics as the text registry

### Security properties preserved

- Keys are read from the environment only, never from the database.
- An adapter with no key reports `isConfigured() === false` and is never selected.
- Errors are redacted before propagation. Gemini needed particular care because its
  key travels in the URL query string, so `key=...` is stripped from error messages
  in addition to the usual bearer-token redaction.

### Fact verification

`app/Libraries/Blog/Verification/`:

- **`ClaimExtractor`** — AI-routed extraction with a deterministic heuristic fallback
  that flags numeric, currency, date and statutory sentences. Tax rates, due dates,
  notifications, penalties and case law classify as `HIGH` risk.
- **`FactVerificationService`** — **fail-closed**. If verification is enabled but the
  verifier is unconfigured or throws, the result is `unavailable`, never `passed`.
  HIGH-risk claims additionally require a source on the authoritative host allowlist
  (`BLOG_AUTHORITATIVE_SOURCE_HOSTS`) or they are reported unsupported.
- **`CrossReviewRouter`** — editorial review is routed to a *different* provider than
  the generator (openai↔gemini), with `assertNotSameProvider()` as a hard guard. The
  verifier is always Perplexity.

`isPublishable()` returns true only when status is `passed`, the pass rate meets the
threshold (default 0.95), and no HIGH-risk claim is unsupported.

---

## 2. Daily roadmap optimiser

### Schema (migrations `2026-07-31-100001` … `100005`)

| Table | Purpose |
|---|---|
| `reach_topic_candidates` | Topic backlog with taxonomy, locking, pinning and cooldown |
| `reach_topic_scores` | Versioned scores; `is_current` marks the live row, with 7d/28d trends |
| `reach_roadmap_decisions` | What the optimiser decided and why, per day |
| `reach_optimizer_runs` | Run ledger with counts, duration and skip reasons |
| `reach_roadmap_scoring_weights` | Single seeded row of tunable weights and deductions |

### Scoring

Eight weighted factors totalling 100: search opportunity (20), product priority (20),
audience problem (15), conversion potential (15), content gap (10), seasonality (10),
internal link value (5), evidence readiness (5).

**A missing signal contributes zero and is recorded in `inputs_json.missing_signals`.**
Signals are never imputed or invented. `RoadmapSignalProvider` guards every query with
a `tableExists()` check, so the optimiser is safe on a partially migrated schema.

Deductions subtract from the total: cannibalisation, near-duplicate, weak evidence,
unavailable product feature, recently generated, and portfolio over-concentration.
Totals are clamped at zero.

### Decisions

`RoadmapDecisionEngine` is deterministic — the same inputs always produce the same
decision, which keeps the roadmap explainable. It emits `CREATE_NEW`,
`REFRESH_EXISTING`, `EXPAND_EXISTING`, `MERGE_COMPETING`, `IMPROVE_TITLE_META`,
`IMPROVE_INTERNAL_LINKS`, `CREATE_PRODUCT_SUPPORT`, `HOLD`, `REJECT` or
`REQUIRE_HUMAN_REVIEW`.

### Stability

`StabilityController` prevents the roadmap thrashing day to day: a minimum score
movement before a re-rank counts, a per-candidate cooldown, a daily candidate cap and
a weekly publication cap.

### Running it

```bash
php spark reach:blog-optimize-roadmap [--force] [--date=YYYY-MM-DD] [--dry-run] [--limit=N]
```

Preferred window is **00:30 Asia/Kolkata**; outside 00:00–00:59 IST the command exits 0
with `outside_preferred_hour` unless `--force` is passed. It takes an exclusive file
lock in `WRITEPATH`, so overlapping cron runs cannot double-execute. `--dry-run` scores
and decides inside a rolled-back transaction.

`CREATE_NEW` decisions create a `GENERATE_BRIEF` work block with idempotency key
`roadmap-{decisionId}`.

---

## 3. Live Search Console connector

`GoogleSearchConsoleConnector` replaces mock-only resolution in
`ConnectorProviderFactory::searchConsole()`. `GoogleServiceAccountToken` performs the
RS256 service-account JWT exchange (scope-agnostic, in-process token cache) so future
Google connectors can reuse it.

### Honest states

The connector never substitutes mock numbers for missing configuration:

| State | Meaning |
|---|---|
| `DISABLED` | `SEARCH_CONSOLE_ENABLED=false` |
| `MISCONFIGURED` | Enabled, but the key path, key file, key contents or site property is missing — the detail string says exactly which |
| `ERROR` | Credentials present but Google rejected them or the API failed |
| `MOCK` | The mock connector is deliberately active |
| `CONNECTED` | Authenticated and the property is reachable |

An unusable connector returns an empty `MetricBatch` with a `warningMessage` rather
than throwing or fabricating rows.

### Data-lag clamping

Search Console finalises data on a 2–3 day lag. `resolveDateWindow()` clamps the end
date to `today - SEARCH_CONSOLE_DATA_LAG_DAYS` and the range to the API's 490-day
limit, so partial days are never ingested as complete. Requests entirely inside the lag
return empty rather than partial data.

Pagination uses `startRow` cursors; HTTP 429 surfaces as an explicit quota error.

---

## 4. Community Q&A receiver (leftover found during audit)

Reach's `CommunityPublicSitePublisher` was already signing and sending community
traffic to `/api/reach/v1/community/*`, and `aicountly-com` already had both the
migration (`005_community_reach_integration.pgsql.sql`) and a fully written
`CommunityReceiverController`. **Nothing routed to it** — there was no front
controller at that path, so every call 404'd against the site's HTML router.

### What was added

- `api/reach/v1/index.php` — front controller that reconstructs the *original* signed
  path before dispatching. This matters: the rewrite strips the prefix into a query
  parameter, and verifying a rewritten path would break every HMAC signature.
- `api/reach/v1/.htaccess` plus a root `.htaccess` rule ordered **before** the static
  file short-circuit, otherwise the existing rules would have swallowed the request.
- A `router.php` branch so the PHP built-in server behaves the same locally.

Authentication reuses the blog publisher's `PublisherAuth` (HMAC-SHA256 over
method + path + body, bearer token, nonce replay store, IP allowlist) via
`CommunityReceiverAuth` — no second crypto implementation was written.

Every route the Reach publisher calls is served: identities, questions, answers,
answer lifecycle (`publish` / `unpublish` / `withdraw` / `restore`), answer status and
verification reads, comments, corrections, moderation actions, record reads, the
changes feed, and reconcile.

### Database-outage bug found and fixed

Smoke-testing the new endpoint with the database down produced a **`Fatal error` with
a full stack trace returned under HTTP 200** instead of a JSON error envelope.

Root cause: `PublisherAuth` built its `NonceStore` in the constructor, and the nonce
store opens a database connection. So a request that should have been rejected cheaply
— receiver disabled, IP not allowlisted, missing or wrong bearer — died before it could
be rejected at all. `PublisherController` had the same shape, eagerly constructing
`PublicationRegistry`, `IdempotencyStore` and its `PDO`.

Both now resolve those collaborators lazily through accessor methods, so the cheap
rejection paths never touch the database. `CommunityReceiverController::handle()` also
wraps authentication in a `try/catch` so an unexpected failure returns a JSON 500
rather than leaking a trace.

This affected the **existing blog publisher**, not just the new receiver — it was a
live latent bug, reachable during any database incident.

`PublisherAuthLazyDatabaseTest` locks the behaviour in. It was verified to be a real
guard: restoring eager construction makes all six of its cases fail with the original
`RuntimeException: Blog module requires PostgreSQL`.

---

## 5. Wiring

- `WorkBlockService` now executes `OPTIMIZE_ROADMAP`, `FACT_VERIFY` and `GENERATE_IMAGE`
  in addition to `PUBLISH_BLOG`. Each checks its own flag and returns a deferred
  outcome with a reason when disabled, so nothing silently no-ops.
- Blog Command Centre overview reports real connector, AI-provider and optimiser state.
- Roadmap screens are no longer scaffolds: candidates, scored (with per-factor
  breakdown and deductions) and optimizer (runs, decisions, editable weights).
- `.env.example` and the WHM/cPanel runbook document every new key, the GSC
  service-account setup, and the 00:30 IST cron entry.

---

## 6. Validation

| Check | Result |
|---|---|
| Reach backend PHPUnit | **1318 passed**, 4564 assertions, 118 skipped (was 1232) |
| Reach frontend Vitest | **336 passed** across 87 files (was 327) |
| Reach ESLint | 0 errors, 4 warnings — all 4 pre-existing, no new warning introduced |
| Reach Vite build | Succeeded, 1850 modules |
| Public site PHPUnit | **53 passed**, 99 assertions (was 14) |
| Lazy-DB regression test | Verified to fail when the fix is reverted |
| PHP syntax lint (all changed and untracked files) | Clean |
| `git diff --check` (all three repos) | Clean |
| `spark list` | All three `reach:blog-*` commands register |
| Window guard smoke test | Correctly skipped with `outside_preferred_hour` at 09:25 IST |

`formatScore` was moved out of `roadmapShared.jsx` into `roadmapFormat.js` so the
component file exports only components; that removed the one new lint warning this work
had introduced and returned the tree to its pre-existing 4-warning baseline.

---

## 7. Flags to enable, in order

```env
# 1. Providers — supply keys first, confirm on Overview → AI Ops
AI_GEMINI_API_KEY=...
AI_PERPLEXITY_API_KEY=...

# 2. Verification (fail-closed; needs Perplexity)
BLOG_FACT_VERIFICATION_ENABLED=true

# 3. Search Console (needs service-account key + property)
SEARCH_CONSOLE_ENABLED=true
BLOG_SEARCH_CONSOLE_ENABLED=true

# 4. Optimiser — run --dry-run first
BLOG_ROADMAP_OPTIMIZER_ENABLED=true

# 5. Only after the above are observed healthy
BLOG_AUTOMATION_ENABLED=true
BLOG_IMAGE_GENERATION_ENABLED=true
```

`BLOG_AUTO_PUBLISH_ENABLED` stays off. High-risk auto-publish remains forbidden in
code regardless of flags.

The Community Q&A receiver is enabled separately, on `aicountly-com`, and only after
migration `005_community_reach_integration.pgsql.sql` has run:

```env
COMMUNITY_REACH_RECEIVER_ENABLED=true
COMMUNITY_REACH_ALLOWED_IPS=<reach server IP>
```

The shared bearer token and HMAC signing key must match the values Reach sends. With
the flag off the receiver answers a clean `503 configuration_error`, which is the
correct state until both sides are configured.

---

## 8. Still open

- **Discovery work blocks** (`DISCOVER_TOPICS`) do not yet populate
  `reach_topic_candidates`; candidates must be added manually until that lands. The
  optimiser handles an empty backlog correctly and reports zero candidates.
- **Roadmap gaps and refresh screens** remain scaffolds.
- Remaining work-block types still return `handler_not_implemented` with that exact
  reason recorded on the block.
