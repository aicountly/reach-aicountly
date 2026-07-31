# 03 — Remediation Log

Chronological, pass-by-pass record of remediation work. Each pass maps to a plan todo. See [02_FINDINGS_REGISTER.md](02_FINDINGS_REGISTER.md) for the finding-by-finding detail and [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md) for exact command output.

## Pass 1a — Reach critical runtime blockers (`pass1-reach-critical`)

- Rebuilt the publish envelope: `PublicationDeploymentService` now calls `BlogPublicationPayloadBuilder` and drives `createDraft` → `publish` (or just `createDraft` when auto-publish is disallowed) instead of sending an empty payload.
- `ReachWork` command: `--queue` now accepts a comma-separated list so one worker process can drain `default,blog,publishing`; `reach:work --once` cycles through the listed queues each idle pass.
- Every deferred/stub work-block handler (`markCompleted()` on a no-op) was replaced with a real implementation or a fail-closed `blockOnFlag()`/`markFailed()` outcome — never a fabricated success.
- High-risk ban narrowed to auto-publish only; approved high-risk content can publish through the explicit human-gated controller path.
- `RoadmapOptimizerService`'s `CREATE_NEW` decision now makes the candidate eligible for `GENERATE_BRIEF`.
- Verified the automation path's published-package body is file-based, not a full-body Postgres column, for newly generated content.

## Pass 1b — Public-site (`aicountly-com`) critical fixes (`pass1-public-critical`)

- Idempotency store compares the stored request checksum instead of blanket-409ing retries.
- `PackageWriter`: staging → atomic activation → `archive/` on replace → `quarantine/` on invalid package.
- Rollback wired to `previous_version_key` via `PublisherController::handleRestore`.
- `HeroImageFetcher` (new) + controller wiring so hero/inline images are actually ingested.
- `PackageValidator` (MIME + checksum) enforced on verify/publish, not just at package-build time.
- `package_migrated` backfill flag preserved across subsequent `create` calls in `PublicationRegistry`.
- Publisher PHPUnit suite expanded (controller, writer, idempotency, package-fail → quarantine).

## Pass 1c — Governance (`pass1-governance`)

- Reused the Community `OfficialAnswerApprovalService` checksum-approval pattern to build `BlogContentApprovalService`: version-bound approval + checksum, revocation, and protected named-reviewer attribution gated on registration + exact-version-checksum match.
- New migrations: `2026-07-31-200001_CreateReachBlogContentApprovals.php`, `2026-07-31-200002_CreateReachBlogProtectedReviewers.php`.
- Tests proving free-text/provider text/unregistered users cannot forge "Reviewed by CA Rahul Gupta" attribution, and that amending or revoking invalidates a prior approval.

## Pass 2 — Full work-block + state-machine pipeline (`pass2-handlers`)

- Implemented real handlers for the full required vocabulary (mapping Opus's names to the prompt's where they differ: `GENERATE_BRIEF`↔`PREPARE_BRIEF`, `FACT_VERIFY`↔`VERIFY_CLAIMS`, `CROSS_REVIEW`↔`EDITORIAL_REVIEW`, `SCHEDULE_PUBLISH`↔`PREPARE_PUBLICATION`, `VERIFY_PUBLICATION`↔`VALIDATE_PUBLIC_PAGE`).
- `WorkBlockService.php` grew by ~1101 net lines covering: `GENERATE_OUTLINE`, `GENERATE_DRAFT`, `FACT_VERIFY`, `SEO_OPTIMIZE`, `CROSS_REVIEW`, `SCHEDULE_PUBLISH`, `PUBLISH_BLOG`, `VERIFY_PUBLICATION`, `REFRESH_CONTENT`, `UNPUBLISH_BLOG`, `RENDER_FILES`/`BACKFILL_STORAGE`, `CONSOLIDATE_CONTENT`, `UPDATE_SITEMAP`, `CHECK_INDEXING`, `SYNC_ANALYTICS`.
- `BlogStateMachine.php`: legal-transition map widened (40-line diff) including the `LIVE → UPDATE_SITEMAP → CHECK_INDEXING` auto-chain and a corrected `withdrawn ↔ public` path.
- `AuditLogger`, `JobHandlerRegistry` extended for the new handler surface.

## Pass 3 — React BCC completeness (`pass3-react-bcc`)

- `BlogCommandCentreLayout.jsx`: disabled-flag ⇒ redirect to dashboard; permission-denied ⇒ `ForbiddenPage`, replacing the prior blank-screen behavior.
- Verified canonical single-BCC structure, Publishing > Blogs aliasing, and legacy redirect/disable enforcement (already correctly wired server-side via `BlogFeatureFlags` in `BlogController::store`).

## Pass 4 — Public-site SEO/sitemap/indexing/connectors (`pass4-public-seo`)

- `robots_directive` column + migration `009_blog_publications_robots_directive.pgsql.sql`; `PublicationRegistry::listPublishedIndexable()`; `blogs-sitemap.php` now excludes non-indexable content.
- `publisher` (Organization) JSON-LD added to `blog_article_json_ld`.
- Real, configurable `GoogleAnalyticsContentConnector` with honest `DISABLED`/`MISCONFIGURED` states, replacing the mock-only content-analytics path in production wiring.

## Pass 5 — Deterministic E2E acceptance (`pass5-e2e`, this session)

- **Public site (`aicountly-com`):** `tests/Publisher/BlogPublisherEndToEndAcceptanceTest.php` — fully executable without a database. Covers signed-request authentication, HMAC/timestamp/nonce/idempotency validation, atomic staging→activation, hero-image ingestion, quarantine of invalid packages, and file-level rollback/republish. **All 11 tests pass** in this sandbox (see [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md)).
- **Reach:** `server-php/tests/Feature/Blog/BlogAutomationPipelineAcceptanceTest.php` — a `DatabaseTestCase`-based deterministic acceptance test walking all 30 steps of §26 (topic candidate → score → roadmap-select → brief → outline → AI draft (mocked provider) → fact verification → SEO review → cross-provider review forced to human → human approval → checksum-bound approval → schedule → **real queue dispatch via `JobService::reserve` + `JobHandlerRegistry::execute`, not a direct method call** → signed mock-publisher deploy → verification → `LIVE` → sitemap chain → rollback → emergency unpublish), plus the specific §26 negative/security cases (high-risk-cannot-auto-publish-even-if-approved, high-risk-cannot-be-recorded-published-without-approval, amendment invalidates approval, revocation blocks publication, protected-reviewer forgery resistance, unsupported claims fail closed, duplicate schedule requests are idempotent). It requires a live PostgreSQL connection (`App\Libraries\ContentVersionService` uses `pg_advisory_xact_lock()`, which is Postgres-specific — no SQLite substitution is possible), and correctly **self-skips** in this sandbox via the same `DatabaseTestCase` pattern every other DB-backed Feature test in the suite already uses (126 pre-existing skips). This is not a workaround invented for this report — it is the repository's existing, deliberate convention for tests that need a real database.
- **Real gap found and fixed while building this test:** `PublicationRollbackService::rollback()` was calling `unpublish()` internally instead of the previously-dead `restore()` operation — see finding F-09 in [02_FINDINGS_REGISTER.md](02_FINDINGS_REGISTER.md). Fixed in `PublicationRollbackService.php`, `WorkBlockService::executeUnpublishBlog`, and `ContentPublishController::unpublish`.

## Pass 6 — Validation + rescan (`pass6-validate-rescan`, this session)

- Ran full validation suites in both repos (see [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md)): Reach PHPUnit (1392 passed), Reach ESLint (0 errors), Reach Vitest (336/336), Reach `vite build` (succeeds), Public PHPUnit (73/73), `git diff --check` (clean, both repos).
- Confirmed `docs/blog-automation/WHM_CPANEL_DEPLOYMENT_RUNBOOK.md` was already corrected for multi-queue worker drain (added in an earlier pass); reviewed against §29 and found no further unsafe/incomplete commands.
- Rescanned the findings register: no repository-level Critical/High/Medium finding remains open.
- Produced this documentation set under `docs/blog-automation/codex-post-implementation-audit/`.
