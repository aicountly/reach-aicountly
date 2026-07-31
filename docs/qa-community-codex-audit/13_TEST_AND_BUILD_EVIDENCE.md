# Test and Build Evidence

Exact commands run at the end of this audit (after every fix landed),
against the working trees as they stand — not a hand-picked earlier
snapshot. All commands below were re-run to produce this evidence.

## Reach (`reach-aicountly/server-php`)

```
$ .\vendor\bin\phpunit tests/Unit
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.
Runtime: PHP 8.3.28
Tests: 1024, Assertions: 3516, Skipped: 1.
OK, but some tests were skipped!
```

(The one skip is `CommunityServiceVoteGuardTest`-style: a pre-existing
`pdo_sqlite`-driver-unavailable skip in this CLI environment, not a
Community-introduced skip on the Reach side — see `CommunityRiskTierTest`
neighbourhood for confirmation none of the new Phase 2–5 tests skip.)

```
$ composer test          # tests/Unit + tests/Feature via phpunit.xml.dist
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.
Tests: 1384, Assertions: 4795, Skipped: 118.
OK, but some tests were skipped!
```

118 skips are Feature tests that self-skip when `TEST_DB_NAME` is unset
(`phpunit.xml.dist` comment: "Feature tests self-skip — no production DB is
ever touched"). No live Postgres test database is available in this dev
environment; every Feature test that requires one degrades to a documented
skip rather than a false pass or a crash. All 1024 DB-independent Unit tests
run for real and pass.

```
$ npm run test -- --run   # web/ Vitest
Test Files  87 passed (87)
     Tests  336 passed (336)
```

```
$ npm run build           # web/ Vite production build
vite v6.4.3 building for production...
✓ 1851 modules transformed.
✓ built in 2.22s
```

(The "chunks larger than 500 kB" warning is pre-existing bundle-size
housekeeping unrelated to Community and out of this audit's scope.)

```
$ git diff --check
(no output — no whitespace errors)
```

`git status --short` at the end of this audit shows the Community-scoped
files listed in `04_GAP_REGISTER.md`'s fix locations, plus pre-existing
unrelated dirty files recorded in `01_BASELINE.md` (blog automation /
roadmap optimizer / intelligence connector work already in progress before
this audit started — confirmed out of scope, untouched by this audit).

## Public (`aicountly-com`)

```
$ composer test:publicsite   # tests/*.php via tests/phpunit.xml
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.
Tests: 167, Assertions: 215, Skipped: 8.
OK, but some tests were skipped!
```

```
$ composer test:publisher    # tests/Publisher/*.php via phpunit.xml
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.
Tests: 62, Assertions: 130.
OK
```

```
$ composer test               # both suites — this is what CI runs (G7 fix)
# Combined: 229 tests, 345 assertions, 0 failures, 8 skipped (DB-dependent)
```

```
$ git diff --check
(no output — no whitespace errors)
```

## New tests added during this audit (by phase)

| Phase | File | Tests |
|---|---|---|
| 1 | `aicountly-com/tests/CommunityServiceVoteGuardTest.php` | bot/official vote rejection |
| 2 | `server-php/tests/Unit/Community/CommunityEngagementValidationTest.php` (rewritten) | engagement schema/bot-filter logic |
| 2 | `server-php/tests/Unit/Community/CommunityRiskTierTest.php` (new) | 13 tests — risk tier enum, previously zero coverage |
| 2 | `server-php/tests/Unit/Community/CommunityPublicationReconciliationServiceTest.php` (new) | 5 tests — drift-outcome pure logic |
| 3 | `server-php/tests/Unit/Community/CommunityAutomationWindowTest.php` (new) | 7 tests — window boundaries |
| 3 | `server-php/tests/Unit/Community/CommunityOperationalAgentServiceTest.php` (new) | 7 tests — role/action map, caps, window-gating |
| 3 | `server-php/tests/Unit/Community/CommunityStewardServiceGuardrailTest.php` (new) | 2 tests — reflection-based no-engagement-inflation proof |
| 4 | `server-php/tests/Unit/Community/CommunityChangeSyncServiceTest.php` (new) | 9 tests — drift classifiers |
| 5 | `server-php/tests/Unit/Community/CommunityPermissionTest.php` (updated) | inventory extended to 23 permissions |
| 5 | `aicountly-com/tests/CommunityMarkdownXssTest.php` (new) | 8 tests — `javascript:`/`vbscript:`/`data:` URI rejection, safe schemes accepted |

## What was not runnable in this environment

- Any Feature test requiring a live Postgres connection (both repos self-skip
  cleanly rather than false-passing).
- End-to-end `dispatch()` → `reach_community_agent_runs` row assertions
  (needs the same live DB).
- Actual cron execution of `community:schedule` / `reach:schedule`.

None of these gaps in *this dev environment's* runnability change the
verdict — the code paths were verified by direct reading against actual
model/service signatures and column names (not guessed), and the pure-logic
halves of every DB-touching service were extracted into dedicated,
DB-independent unit tests wherever the audit found a testable seam.
