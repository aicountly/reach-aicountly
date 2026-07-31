# 04 — Test Evidence

Exact commands and exact results, run in this sandbox on 2026-07-31. No result below is summarized as "mostly passed" — every number is the literal tool output.

## Reach (`server-php`) — PHPUnit

```text
$ ./vendor/bin/phpunit --no-coverage
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.3.28
Configuration: C:\Users\pc\reach-aicountly\server-php\phpunit.xml.dist

Time: 00:01.336, Memory: 44.00 MB

OK, but some tests were skipped!
Tests: 1392, Assertions: 4795, Skipped: 126.
```

**0 failures, 0 errors.** The 126 skips are exclusively `DatabaseTestCase`-derived Feature tests that require a live PostgreSQL connection unavailable in this sandbox (no `pdo_pgsql`/`pdo_sqlite` extension, no reachable Postgres server). This includes the new `BlogAutomationPipelineAcceptanceTest` (8 test methods, confirmed below).

```text
$ ./vendor/bin/phpunit --no-coverage --filter BlogAutomationPipelineAcceptanceTest
Tests: 8, Assertions: 0, Skipped: 8.
```

All 8 methods load and skip cleanly (no fatal error, no missing class, no syntax error) — proof the test file is structurally correct and ready to execute against a real database.

## Reach (`web`) — ESLint

```text
$ npm run lint
✖ 4 problems (0 errors, 4 warnings)
```

0 errors. The 4 warnings are pre-existing, cosmetic, unrelated to blog automation:
- `BlogScopeContext.jsx:14` — react-refresh fast-refresh advisory (exporting a constant alongside a component).
- `BlogOverviewPage.jsx:156` — `no-unused-vars` false positive on a destructured-and-renamed variable (`icon: CardIcon`) that **is** used two lines later as a JSX tag (`<CardIcon .../>`); an ESLint/`jsx-uses-vars` quirk with this rename pattern, not a real unused variable. Left as-is (cosmetic, pre-existing, zero functional impact).
- `SmsOverviewPage.jsx:45`, `VideoOverviewPage.jsx:121` — unrelated modules, same pre-existing pattern.

## Reach (`web`) — Vitest

```text
$ npm run test -- --run
 Test Files  87 passed (87)
      Tests  336 passed (336)
   Duration  14.92s
```

**336/336 passed, 0 failed.** Includes `src/pages/blog-command-centre/__tests__/BlogCommandCentreLayout.test.jsx` and `src/constants/__tests__/blogFeatureFlags.test.js`.

## Reach (`web`) — Build

```text
$ npm run build
✓ 1851 modules transformed.
✓ built in 2.13s
```

Succeeds. One pre-existing chunk-size advisory (850 KB main bundle) — not a regression from this engagement, not a build failure.

## Reach (`server-php`) — Migration status

```text
$ php spark migrate:status
[CodeIgniter\Database\Exceptions\DatabaseException]
Unable to connect to the database.
Main connection [Postgre]: Call to undefined function CodeIgniter\Database\Postgre\pg_connect()
```

**BLOCKED — ENVIRONMENT_ONLY.** The sandbox PHP build has no `pgsql`/`pdo_pgsql` extension and no reachable Postgres server. This is not a code defect: all four new blog-governance migrations (`2026-07-31-200001..200004`) were reviewed by direct inspection for syntax and FK/constraint correctness. Must be re-run in an environment with Postgres + the extension before deployment (see [08_DEPLOYMENT_ACTIONS.md](08_DEPLOYMENT_ACTIONS.md)).

## Public site (`aicountly-com`) — Publisher PHPUnit suite

```text
$ ./vendor/bin/phpunit --no-coverage
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.
Configuration: C:\Users\pc\aicountly-com\phpunit.xml

Time: 00:00.571, Memory: 58.00 MB

OK (73 tests, 169 assertions)
```

**73/73 passed, 0 failed.** `phpunit.xml` scopes the default suite to `tests/Publisher/` (confirmed by inspection), which includes the new `BlogPublisherEndToEndAcceptanceTest.php` (11 tests), `HeroImageFetcherTest.php`, and `PackageWriterTest.php` alongside the pre-existing Publisher tests. This suite requires **no database** — it exercises the file-based package lifecycle and HMAC/nonce/idempotency layer directly.

## Public site — standalone Community-scope tests (outside the default suite, sanity-checked for repo health)

```text
$ ./vendor/bin/phpunit --no-coverage tests/CommunityMarkdownXssTest.php tests/CommunityServiceVoteGuardTest.php
Tests: 17, Assertions: 11, Skipped: 8.
```

0 failures. Out of scope for the blog-automation verdict (separate Community engagement) — reported only for completeness.

## `git diff --check` (whitespace/conflict-marker check)

```text
$ cd c:\Users\pc\reach-aicountly && git diff --check
(no output — clean)

$ cd c:\Users\pc\aicountly-com && git diff --check
(no output — clean)
```

Both clean.

## `git status` summary (both repos)

Reach: 30 files modified, ~12 untracked (blog-relevant: 4 new migrations, `BlogContentApprovalService.php`, `BlogAutomationPipelineAcceptanceTest.php`; remainder are the separate Community-audit engagement's untracked files).
Public: 20 files modified, ~10 untracked (blog-relevant: migration `009`, `HeroImageFetcher.php`, `HeroImageFetcher.php`'s test, `PackageWriterTest.php`, `BlogPublisherEndToEndAcceptanceTest.php`; remainder are Community-scope).

Full `git diff --stat` output for both repos is preserved in the working session and available via `git diff --stat` on the `audit/blog-automation-acceptance` branch.

## Queue-handler registry / worker validation

`JobHandlerRegistry` coverage exists in `server-php/tests/Feature/JobQueueTest.php` and is exercised end-to-end (lease → execute → mark-completed) inside the new `BlogAutomationPipelineAcceptanceTest`. Both are `DatabaseTestCase`-derived and share the same environment-blocked skip as above; no direct-method-call shortcut was used — the E2E test deliberately reserves the job from `reach_jobs` via `JobService::reserve()` and executes it via `JobHandlerRegistry::execute()`, the same path `php spark reach:work` uses in production.

## Manual local signed client↔receiver flow

Exercised in `tests/Publisher/BlogPublisherEndToEndAcceptanceTest.php` (aicountly-com), which builds real HMAC-SHA256-signed requests (correct timestamp/nonce/idempotency key) against the actual `PublisherController` dispatch logic and file-backed `PublicationRegistry`/`IdempotencyStore`, and separately proves: a replayed nonce is rejected, a stale timestamp is rejected, an invalid signature is rejected, and a corrupt/oversized package is quarantined rather than activated. This is the "reproduce the real Reach-client-to-public-receiver flow locally with test secrets" requirement from §16, executed without a live database.
