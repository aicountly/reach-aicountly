# Phase 0 Exit Audit

- **Branch:** `feature/phase-0-foundation`
- **Baseline commit:** `1766ec2`
- **Audit date:** on Phase 0 close
- **Scope:** Stability, RBAC, Security, Async Job Foundation

## Cross-check with `REACH_PROPOSED_PHASE_PLAN.md` — Phase 0 Exit checklist

| # | Item                                                    | Status | Evidence |
|---|---------------------------------------------------------|--------|----------|
| 1 | Re-run build/lint/test — all pass                       | Passed | `.github/workflows/ci.yml`, local `vendor/bin/phpunit --testsuite Unit` (24/24) |
| 2 | Role matrix documented                                  | Passed | `docs/architecture/REACH_RBAC.md` |
| 3 | No dead routes/components                               | Passed | `LoginPage.jsx` removed; `EngagePushController::attempts()` removed; legacy script archived |
| 4 | Audit logs capture queue job execution                  | Passed | `job.enqueued/reserved/completed/retried/failed/cancelled` emitted by `JobService`; `reach_audit_logs.job_id` populated |

## 16 inspection points

Even though the plan file listed a 4-line Phase 0 exit checklist,
Section 15 of the Marketing Automation brief called for a 16-point
end-of-phase inspection. The inspection matrix below is authoritative.

| #  | Inspection point                                                        | Status | Evidence |
|----|-------------------------------------------------------------------------|--------|----------|
| 1  | Frontend lint passes without rule suppressions                          | Passed | `web/eslint.config.js`, CI workflow `lint` step |
| 2  | Backend `php -l` passes on every touched file                           | Passed | Manual sweep during implementation; CI job `syntax` step |
| 3  | Frontend build succeeds                                                 | Passed | `web/package.json` `build` script, run in CI |
| 4  | Frontend tests present + green                                          | Passed | `web/src/**/__tests__/*` (5 files) |
| 5  | Backend unit tests present + green                                      | Passed | `tests/Unit/*` (4 files, 24 assertions locally) |
| 6  | Backend feature tests present (skip gracefully w/o DB)                  | Passed | `tests/Feature/*` (7 files, gated on `TEST_DB_NAME`) |
| 7  | All routes require an explicit permission slug                          | Passed | `app/Config/Routes.php` — no blanket group filter |
| 8  | Six seeded roles distinct + super-admin wildcard preserved              | Passed | `RolesAndPermissionsSeeder` |
| 9  | Per-user permission overrides supported (grant/deny)                    | Passed | `reach_user_permissions` migration + `PermissionService::resolveEffective` |
| 10 | Bot dispatch async (202 + job id), Job Monitor renders + operates       | Passed | `MarketingBotController::dispatch`, `JobController`, `JobMonitorPage.jsx` |
| 11 | Rate limiting active with 429 + Retry-After + audit                     | Passed | `RateLimitFilter`, `RateLimits.php` |
| 12 | HTML sanitisation active on blog/campaign/landing                       | Passed | `HtmlSanitizer` + controllers |
| 13 | URL policy blocks loopback/private/link-local/metadata                  | Passed | `UrlPolicy`, `tests/Unit/UrlPolicyTest.php` (10 tests) |
| 14 | Payload validation + JSON body size cap                                 | Passed | `RequestValidator`, `JsonBodySizeFilter` (global), `Enums` |
| 15 | Secrets redacted in audit/jobs/settings                                 | Passed | `SecretRedactor`, `tests/Unit/SecretRedactorTest.php` (7 tests) |
| 16 | Correlation IDs in/out (HTTP + worker) with X-Request-Id header         | Passed | `RequestIdFilter`, `ReachWork` restore, `AicountlySitePublisher`/`EngageClient`/`ConsoleAuditClient` outbound header |

## Acceptance-criteria matrix

Copied from `PHASE_0_IMPLEMENTATION_REPORT.md` for convenience; see there
for evidence links.

| # | Criterion                                                           | Status |
|---|---------------------------------------------------------------------|--------|
| 1 | Lint passes without rule suppression                                | Passed |
| 2 | Vitest + PHPUnit configured and running in CI                       | Passed |
| 3 | ≥5 meaningful frontend tests                                        | Passed |
| 4 | ≥10 meaningful backend tests                                        | Passed |
| 5 | Blanket `super-admin` filter removed                                | Passed |
| 6 | Granular RBAC with 6 seeded roles                                   | Passed |
| 7 | Per-user permission overrides                                       | Passed |
| 8 | Actor model on all actor-aware tables                               | Passed |
| 9 | Async job queue with retry + dead-letter                            | Passed |
| 10 | Bot dispatch fully async (202 + job id)                            | Passed |
| 11 | Job monitor UI with retry/cancel                                   | Passed |
| 12 | Rate limiting with 429 + Retry-After                               | Passed |
| 13 | HTML sanitisation on blog/campaign/landing rich fields             | Passed |
| 14 | URL/SSRF policy on outbound integrations                           | Passed |
| 15 | Payload validation + JSON body size cap                            | Passed |
| 16 | Secret redaction across audit / jobs / settings                    | Passed |
| 17 | Extended audit log columns + events                                | Passed |
| 18 | Request correlation IDs                                            | Passed |
| 19 | cPanel-safe worker + scheduler docs                                | Passed |
| 20 | No real AI provider calls / no real publishing                     | Passed |
| 21 | Approval policy with self-approve + override                       | Passed |

## Risks and confirmations

### Explicit non-goals confirmed still not implemented

- No real OpenAI / Gemini / Anthropic / Grok / Perplexity calls.
- No prompt management library.
- No product knowledge graph.
- No AI-authored blog content.
- No KB / community / video / SMS module.
- No real social / email / WhatsApp send.
- No GSC / Bing / IndexNow integration.
- No AI visibility monitoring.
- No customer tenancy / branch / FY context.
- No cross-portal shared identity for Reach users.

### Deferred to later phases

- Redis-backed queue (would allow long-lived workers off-cPanel).
- Full-text search on `reach_audit_logs` (planned Phase 1 with GIN index).
- Rich role editor UI (Phase 0 seeded roles are read-only from the app).
- Per-org rate-limit quotas (Phase 0 policies are global slugs).

### Confirmations

- **No production credentials, no external provider calls** are made
  from code or tests in Phase 0.
- All new migrations use the existing
  `YYYY-MM-DD-NNNNNN_Name.php` naming convention.
- All Phase 0 tests either pass or self-skip when their prerequisites
  (test Postgres) are unavailable.
- The Phase 0 feature branch does not touch the deploy workflow.
- No changes to the Console SSO contract or to Engage's inbound token
  scheme.

## Ready-for-merge checklist

- [x] `.github/workflows/ci.yml` present and green on the branch.
- [x] `docs/architecture/REACH_RBAC.md` reflects the shipped route matrix.
- [x] `docs/architecture/REACH_JOB_QUEUE.md` reflects the shipped schema.
- [x] `docs/architecture/REACH_SECURITY_CONTROLS.md` reflects the shipped
      rate-limit / sanitiser / URL / secret policies.
- [x] `docs/operations/REACH_WORKER_AND_CRON.md` documents cron patterns.
- [x] `docs/phases/PHASE_0_IMPLEMENTATION_REPORT.md` maps every task to
      code and tests.
- [x] `docs/phases/PHASE_0_EXIT_AUDIT.md` (this file) records the exit
      audit result.
- [ ] Branch protection rule on `main` requires the CI workflow to pass
      (out of scope for this branch — documented for the maintainer).
