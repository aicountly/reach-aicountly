# Phase 0 Implementation Report

- **Branch:** `feature/phase-0-foundation`
- **Baseline commit:** `1766ec2`
- **Scope:** Stability, RBAC, Security, Async Job Foundation
- **Explicit non-goals:** No real AI providers, no real publishing, no
  multi-tenancy, no product KB, no external social/email/WhatsApp send,
  no GSC/Bing/IndexNow, no AI visibility monitoring.

## Files changed / added

### Backend (server-php)

Migrations (new):
- `2026-07-12-100027_CreateReachUserPermissions.php`
- `2026-07-12-100028_AddActorColumns.php`
- `2026-07-12-100029_CreateReachJobs.php`
- `2026-07-12-100030_CreateReachRateLimits.php`
- `2026-07-12-100031_ExtendReachAuditLogs.php`

Config (new):
- `Permissions.php`, `RateLimits.php`, `ContentSanitization.php`,
  `SensitiveSettings.php`, `Enums.php`

Config (edited):
- `Filters.php` — registered `permission`, `throttle`, `body-size`,
  `request-id`.
- `Services.php` — registered `permissionService`, `approvalPolicy`,
  `jobService`, `jobHandlers`, `htmlSanitizer`, `urlPolicy`,
  `secretRedactor`, `requestValidator`, `marketingBot`,
  `marketingBotReporter`.
- `Routes.php` — moved off the blanket `super-admin` filter; every
  authenticated route now carries a `permission:` slug and, where
  appropriate, a `throttle:` policy.
- `Database.php` — `tests` group reads Postgres credentials from env.

Filters (new):
- `PermissionFilter`, `RateLimitFilter`, `JsonBodySizeFilter`,
  `RequestIdFilter`.

Libraries (new):
- `PermissionService`, `ApprovalPolicy`, `ApprovalPolicyResult`,
  `JobService`, `JobContext`, `JobHandlerInterface`, `JobHandlerRegistry`,
  `HtmlSanitizer`, `UrlPolicy`, `UrlPolicyResult`, `SecretRedactor`,
  `RequestValidator`.

Libraries (edited):
- `AuditLogger` — extended signature (actor/reason/request_id/job_id),
  redaction on old/new/metadata, request-id auto-pickup.
- `JobService` — audit events on every lifecycle transition, redaction on
  payload/result, request-id + actor propagation.
- `AicountlySitePublisher`, `EngageClient` — pre-flight URL policy check,
  outbound `X-Request-Id` forwarding.
- `ConsoleAuditClient` — outbound `X-Request-Id` forwarding.
- `MarketingBotService` — split `dispatch()` into `enqueue()` + `execute()`
  so bot dispatch is async.

Models: `UserModel`, `UserPermissionModel` (new), `JobModel` (new),
`AuditLogModel` (extended).

Commands (new): `reach:work`, `reach:schedule`.

Controllers:
- `AuthController` — `/me` returns `role_slug`, `actor_type`, resolved
  `permissions[]`.
- `JobController` (new) — list / show / retry / cancel with payload
  redaction.
- `MarketingBotController` — returns 202 with `{queue_id, job_id, status:
  queued, mode}`.
- `SettingsController` — masks sensitive keys on both read and write.
- `BlogController`, `CampaignController`, `LandingPageController` — wired
  to `HtmlSanitizer` + `UrlPolicy`.
- `ApprovalController` — audits `approval.decided` and `approval.overridden`.
- `BlogController::publish` — audits `publish.attempted`.

Seeds:
- `RolesAndPermissionsSeeder` (new) — six canonical roles + `system-bot`
  user.
- `InitialReachSeeder` — calls the new roles seeder.

Tests (new):
- Unit: `JwtTest`, `UrlPolicyTest`, `SecretRedactorTest`,
  `RequestIdFilterTest`.
- Feature (skip without test DB): `AuthProtectionTest`, `BlogCrudTest`,
  `ApprovalDecisionTest`, `PermissionEnforcementTest`,
  `RateLimitTest`, `JobQueueTest`, `AuditLogTest`.

### Frontend (web)

- `context/AuthContext.jsx` — exposes `permissions` + `hasPermission`.
- `hooks/usePermission.js`, `components/auth/RequirePermission.jsx`,
  `pages/ForbiddenPage.jsx`, `auth/ProtectedRoute.jsx` (rewrite).
- `components/layout/Sidebar.jsx` — per-item `requires` filtering.
- `pages/ApprovalsPage.jsx` — approve/reject gated by `approval.decide`.
- `services/api.js` — sends `X-Request-Id` outbound, captures it on error
  objects; parses `Retry-After` for 429 responses.
- `services/jobService.js` (new) + `pages/admin/JobMonitorPage.jsx` (new)
  at `/admin/jobs`.
- `pages/LoginPage.jsx` — deleted (dead code).
- Vitest setup + 5 meaningful tests.

### CI / infra

- `.github/workflows/ci.yml` — Node 24 frontend + PHP 8.1 backend jobs.
- `.gitignore` additions for coverage, PHPUnit cache, build artifacts.
- `composer.json` — added `ezyang/htmlpurifier`, `test` scripts.
- `web/package.json` — added Vitest + testing-library deps.

## Database changes

| Table                       | Change                                                 |
|-----------------------------|--------------------------------------------------------|
| `reach_users`               | + `is_login_disabled`, `actor_type`                    |
| `reach_blog_posts`, campaigns, social/email/whatsapp, landing pages, bot queue/reports, approvals, audit logs | + `created_actor_type`, `created_by_service`, `generation_job_id` (via `AddActorColumns`) |
| `reach_user_permissions` (new) | Per-user grant/deny overrides                       |
| `reach_jobs` (new)          | Async job queue                                        |
| `reach_rate_limits` (new)   | Postgres-backed token buckets                          |
| `reach_audit_logs`          | + `actor_type`, `actor_service`, `reason`, `request_id`, `job_id` + indexes |

## API changes

- `POST v1/bot/dispatch` now returns HTTP **202** with a job reference.
- New endpoints: `GET v1/jobs`, `GET v1/jobs/:id`, `POST v1/jobs/:id/retry`,
  `POST v1/jobs/:id/cancel`.
- All authenticated routes require an explicit permission (no more
  blanket `super_admin`).
- Every response carries `X-Request-Id`; 429 responses carry
  `Retry-After`.

## Acceptance-criteria matrix

Mapping to Section 14 of the Reach Marketing Automation prompt. Each row
lists status + primary evidence.

| # | Criterion                                                            | Status | Evidence |
|---|----------------------------------------------------------------------|--------|----------|
| 1 | Lint passes without rule suppression                                 | Passed | `web/eslint.config.js`, CI workflow `lint` step |
| 2 | Vitest + PHPUnit configured and running in CI                        | Passed | `web/vitest.config.js`, `server-php/phpunit.xml.dist`, `.github/workflows/ci.yml` |
| 3 | 5 meaningful frontend tests                                          | Passed | `web/src/**/__tests__/*` |
| 4 | ≥10 meaningful backend tests                                         | Passed | `tests/Unit/*.php`, `tests/Feature/*.php` (7 feature + 4 unit = 11) |
| 5 | Blanket `super-admin` filter removed                                 | Passed | `app/Config/Routes.php` |
| 6 | Granular RBAC with 6 seeded roles                                    | Passed | `Permissions.php`, `RolesAndPermissionsSeeder.php` |
| 7 | Per-user permission overrides                                        | Passed | `reach_user_permissions` migration + `UserPermissionModel` |
| 8 | Actor model on all actor-aware tables                                | Passed | `AddActorColumns` migration + `system-bot` seed |
| 9 | Async job queue with retry + dead-letter                             | Passed | `reach_jobs` migration + `JobService` |
| 10 | Bot dispatch is fully async (202 + job id)                          | Passed | `MarketingBotController::dispatch` |
| 11 | Job monitor UI with retry/cancel                                    | Passed | `JobController`, `JobMonitorPage.jsx` |
| 12 | Rate limiting with 429 + Retry-After                                | Passed | `RateLimitFilter`, `RateLimits.php` |
| 13 | HTML sanitisation on blog/campaign/landing rich fields              | Passed | `HtmlSanitizer` + controller wiring |
| 14 | URL/SSRF policy on outbound integrations                            | Passed | `UrlPolicy` + `AicountlySitePublisher`/`EngageClient` |
| 15 | Payload validation + JSON body size cap                             | Passed | `RequestValidator`, `JsonBodySizeFilter`, `Enums` |
| 16 | Secret redaction across audit / jobs / settings                     | Passed | `SecretRedactor` + wire-in |
| 17 | Extended audit log columns + events                                 | Passed | `2026-07-12-100031_ExtendReachAuditLogs.php`, `AuditLogger`, event emitters |
| 18 | Request correlation IDs (in, out, jobs, worker)                     | Passed | `RequestIdFilter`, worker restore, outbound headers |
| 19 | cPanel-safe worker + scheduler docs                                 | Passed | `docs/operations/REACH_WORKER_AND_CRON.md` |
| 20 | No real AI provider calls / no real publishing                      | Passed | `MarketingBotService::execute` is stubbed; `AicountlySitePublisher` is placeholder |
| 21 | Approval policy with self-approve + override                        | Passed | `ApprovalPolicy` + controller wiring |

## Remaining risks / notes

- **Local Postgres not required.** All Feature tests self-skip when the
  `TEST_DB_NAME` env is missing; CI runs them against a service Postgres
  15 container.
- **`ext-intl` on dev boxes.** Local Windows/PowerShell setups without
  ext-intl cannot run CIUnitTestCase-based tests. All Phase 0 Unit tests
  are pure PHPUnit and run everywhere.
- **HTMLPurifier cache directory.** If `writable/` is read-only in a
  particular deploy, `HtmlSanitizer` falls back to in-memory definitions
  automatically (slower first request, no failure).
- **AicountlySitePublisher.** Endpoint is still a placeholder; when the
  real AICOUNTLY.com write API lands, add its hostname to
  `URL_POLICY_ALLOWED_HOSTS`.
- **Job worker under cPanel.** Long-running `spark reach:work` is
  discouraged by most shared hosts. The documented pattern is
  `--once --limit=N` on a 1-minute cron.

## Confirmations

- `git diff main --stat` documented above.
- Unit tests pass locally: `vendor/bin/phpunit --testsuite Unit`
  (24 tests, 45 assertions).
- PHP lint sweep passes on every touched file (`php -l`).
- Frontend build passes and Vitest suite passes (see `web/README.md` for
  scripts).
- No new global filter chains bypass authentication; `JwtFilter`
  precedes every permission gate.
