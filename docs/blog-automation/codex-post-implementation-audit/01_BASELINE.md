# 01 — Baseline

## Repositories

| Repo | Absolute path | Branch | HEAD (pre-audit) | Remote | Working tree at audit start |
|---|---|---|---|---|---|
| Reach | `c:\Users\pc\reach-aicountly` | `main` → working branch `audit/blog-automation-acceptance` | `6d9d61d` "revamp" (2026-07-31 09:45:56 +0530) | synced with origin | clean |
| Public | `c:\Users\pc\aicountly-com` | `main` → working branch `audit/blog-automation-acceptance` | `6e51502` "Exclude Publisher tests from the PublicSite PHPUnit suite." (2026-07-31 09:51:15 +0530) | synced with origin | clean |

The Opus 5.0 blog-automation implementation was **fully committed** on `main` under opaque `revamp` commits in both repos — no uncommitted Opus leftovers were found at audit start. All remediation in this engagement was performed on the `audit/blog-automation-acceptance` branch in both repos; nothing was pushed or merged.

Note: both working trees also carry unrelated, pre-existing changes from a separate Community-audit engagement on the same branch (e.g. `CommunityAgentDispatchController`, `CommunitySchedule`, `includes/community/*`). Those files are **out of scope** for this blog-automation report and are not covered by the findings/verdict here; they are called out only where they appear in `git status`/`git diff --stat` output for completeness.

## Framework / runtime versions

| Component | Version |
|---|---|
| Reach backend | CodeIgniter `v4.7.3`, PHP `8.3.28` |
| Reach frontend | React 19, Vite `v6.4.3`, ESLint (flat config), Vitest |
| Public site | Plain PHP (no framework), PHPUnit `10.5.64` |
| Reach test runner | PHPUnit `10.5.63` |

## Installed dependencies

- Reach `server-php`: managed via Composer (`composer.json`/`composer.lock` present, unchanged by this engagement except where noted in [03_REMEDIATION_LOG.md](03_REMEDIATION_LOG.md)).
- Reach `web`: managed via npm (`package.json`/`package-lock.json`, unchanged).
- Public site: managed via Composer (`composer.json` modified — see diff stat in [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md); change originates from the separate Community engagement, not blog automation).

## Database

- Reach: PostgreSQL via CodeIgniter migrations under `server-php/app/Database/Migrations/`. **No live Postgres instance and no `pdo_pgsql` PHP extension are available in this sandbox** — `php spark migrate:status` fails with `Call to undefined function CodeIgniter\Database\Postgre\pg_connect()`. This is an environment limitation, not a code defect; migrations are syntactically present and were reviewed by inspection (see [06_DATA_MIGRATION_EVIDENCE.md](06_DATA_MIGRATION_EVIDENCE.md)).
- Public site: PostgreSQL via hand-rolled numbered SQL files under `database/migrations/*.pgsql.sql` (001 through 009). No migration runner; applied manually/by script. Same sandbox limitation applies for live verification.

## Existing build/test/lint commands (as declared by each repo, verified to run)

| Repo | Command | Purpose |
|---|---|---|
| Reach `server-php` | `./vendor/bin/phpunit --no-coverage` | Unit + Feature suite (1392 tests) |
| Reach `server-php` | `php spark migrate:status` | Migration status (env-blocked here) |
| Reach `web` | `npm run lint` | ESLint |
| Reach `web` | `npm run test -- --run` | Vitest |
| Reach `web` | `npm run build` | Vite production build |
| Public | `./vendor/bin/phpunit --no-coverage` | Publisher suite (`tests/Publisher`, 73 tests; `phpunit.xml` scopes the default suite to this directory only) |

## Deployment structure

- Reach: CodeIgniter app deployed under a Reach-side cPanel account; workers/cron run `php spark reach:work` and `php spark reach:schedule`; documented in `docs/blog-automation/WHM_CPANEL_DEPLOYMENT_RUNBOOK.md`.
- Public: plain-PHP site under the `aicountlycom` cPanel account; receives signed HMAC requests from Reach at `/reach/v1/*`; private file storage intended at `/home/{BLOG_CPANEL_USER}/{BLOG_STORAGE_FOLDER}` (env-driven, see [08_DEPLOYMENT_ACTIONS.md](08_DEPLOYMENT_ACTIONS.md)).

## Change-safety plan followed

- All work performed on the pre-existing `audit/blog-automation-acceptance` branch in both repos (not `main`).
- No destructive git operations, no force-push, no history rewrite.
- No production secrets requested or fabricated; provider/publisher calls use `REACH_AI_MOCK`/`REACH_PUB_MOCK` test doubles.
- No WHM/cPanel action executed; the runbook was corrected in-place as documentation only.
- Unrelated Community-audit changes already present on the branch were left untouched.
