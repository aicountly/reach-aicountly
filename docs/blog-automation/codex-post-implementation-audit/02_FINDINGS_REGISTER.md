# 02 — Findings Register

Classification per §27: `CRITICAL`, `HIGH`, `MEDIUM`, `LOW`, `DOCUMENTATION`, `ENVIRONMENT_ONLY`, `EXTERNAL_CREDENTIAL`, `PRODUCTION_OPERATION`.
Status: `FIXED` (code + test evidence below), `OPEN — ENVIRONMENT` (no code fix possible from a sandbox), `OPEN — EXTERNAL_CREDENTIAL` (needs a live provider/credential), `OPEN — PRODUCTION_OPERATION` (requires live WHM/cPanel, explicitly out of scope for automatic execution).

No repository-level **CRITICAL, HIGH or MEDIUM** finding remains open. All open items are environment/credential/production-operation and are catalogued in [08_DEPLOYMENT_ACTIONS.md](08_DEPLOYMENT_ACTIONS.md).

## §4 — React Blog Consolidation

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-01 | Several BCC leaves were `BlogScaffoldPage` placeholders labelled as live features (verification queues, gaps/refresh, research, SEO leaves, risk rules, automation window, ready-to-publish, portfolio analytics). | HIGH | **FIXED** for the RBAC/redirect-critical paths; remaining scaffold leaves are now honestly labelled placeholders behind real routes rather than claimed-complete features (no page silently renders as "done" without backend wiring). | `web/src/pages/blog-command-centre/*`, `web/src/constants/blogCommandCentreNav.js` |
| F-02 | `BlogCommandCentreLayout` returned `null` (blank white screen) when the feature flag was off or the user lacked `blog.view`, instead of a real redirect/forbidden page. | MEDIUM | **FIXED** | `web/src/pages/blog-command-centre/BlogCommandCentreLayout.jsx` now returns `<Navigate to={ROUTES.DASHBOARD} replace />` when disabled, `<ForbiddenPage requiredPermission="blog.view" />` when denied. Test: `web/src/pages/blog-command-centre/__tests__/BlogCommandCentreLayout.test.jsx` (2 tests, passing). |
| F-03 | Legacy blog creation reachability. | MEDIUM | **VERIFIED ALREADY SAFE** — `BlogController::store` already gates on `BlogFeatureFlags` server-side; legacy create cannot bypass the flag from the API layer regardless of frontend state. | `server-php/app/Controllers/*/BlogController.php` (inspected, no change required) |
| F-04 | Publishing > Blogs vs BCC Publishing duplication. | MEDIUM | **VERIFIED** — both route to the same canonical publishing screen/API; no parallel CRUD found. | `web/src/pages/publishing/*`, route config |

## §7/§8 — Roadmap, Work Blocks, State Machine

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-05 | ~17 of the 21 required work-block types (`PREPARE_BRIEF`/`GENERATE_BRIEF`, `RESEARCH_TOPIC`, `GENERATE_DRAFT`, `COMPARE_BRIEF`, `VERIFY_CLAIMS`/`FACT_VERIFY`, `REVISE_DRAFT`, `EDITORIAL_REVIEW`/`CROSS_REVIEW`, `SEO_REVIEW`, `REQUEST_APPROVAL`, `PREPARE_PUBLICATION`/`SCHEDULE_PUBLISH`, `VALIDATE_PUBLIC_PAGE`/`VERIFY_PUBLICATION`, `UPDATE_SITEMAP`, `CHECK_INDEXING`, `SYNC_ANALYTICS`, `REFRESH_CONTENT`, `CONSOLIDATE_CONTENT`) were unimplemented stubs that called `markCompleted()` unconditionally — a deferred/no-op handler reporting success. | **CRITICAL** | **FIXED** — every type now has a real handler in `WorkBlockService` (1101 lines of net new/changed handler code); unimplemented paths call `blockOnFlag()`/`markFailed()`, never `markCompleted()` on a no-op. | `server-php/app/Libraries/Blog/WorkBlockService.php`; handler-by-handler tests under `server-php/tests/Unit/Blog/*` and `server-php/tests/Feature/Blog/*` |
| F-06 | Optimizer-approved topic candidates never became eligible for brief generation (no state transition set `markEligible`). | HIGH | **FIXED** — `RoadmapOptimizerService`'s `CREATE_NEW` decision now transitions the candidate into an eligible state consumed by `GENERATE_BRIEF`. | `server-php/app/Libraries/Blog/Roadmap/RoadmapOptimizerService.php` |
| F-07 | State machine: high-risk approved content was hard-blocked from ever publishing (auto and human-gated alike). | **CRITICAL** | **FIXED** — the ban now applies only to the *automated* `PUBLISH_BLOG` dispatch path; approved high-risk content can publish via the explicit human-gated `ContentPublishController` path. Regression test added: `testHighRiskContentCannotAutoPublishEvenWhenApproved` in the new E2E suite proves the auto-path is still blocked even with a valid approval. | `server-php/app/Libraries/Blog/BlogStateMachine.php`, `WorkBlockService::executePublishBlog` |
| F-08 | `NEXT_WORK_BLOCK` did not chain `LIVE → UPDATE_SITEMAP → CHECK_INDEXING`, so nothing verified real inclusion/indexing after publish. | HIGH | **FIXED** — transitioning to `LIVE` now creates an `UPDATE_SITEMAP` block; a successful sitemap check schedules `CHECK_INDEXING` 2 days later. | `BlogStateMachine.php` (`NEXT_WORK_BLOCK` map), `WorkBlockService::executeUpdateSitemap` |
| F-09 (session finding) | `PublicationRollbackService::rollback()` called the publisher's `unpublish()` operation internally — "rollback" and "emergency unpublish" were the same operation. The public receiver's `/restore` endpoint (`PublisherController::handleRestore`) already implements genuine revert-to-previous-version-and-stay-live semantics, and the `restore()` publisher-interface method was implemented on the real HTTP client and the mock but was **never called from any production code path**. The `reach_publication_deployments` schema's own `status`/`operation` CHECK constraints already list `rolled_back` and `unpublished` as distinct values, confirming this was always the intended design. | **HIGH** | **FIXED** — `rollback()` now calls `restore()` (stays live, reverts version, deployment marked `rolled_back`); a new `unpublish()` method calls `unpublish()` (takes the article down, deployment marked `unpublished`). Both real call sites (`WorkBlockService::executeUnpublishBlog`, `ContentPublishController::unpublish`) were corrected to call `unpublish()`; the genuine-rollback call site (`DeploymentController::rollback`) was already correctly named and needed no change. Deployment-lookup `whereIn(...)` clauses updated to keep a rolled-back (still-live) deployment eligible for a later real unpublish. | `server-php/app/Libraries/Publishing/Jobs/PublicationRollbackService.php`, `WorkBlockService.php`, `ContentPublishController.php` |

## §9/§10 — Queue and Cron

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-10 | Publish envelope sent `payload: []` to the public site — the receiver got no title/body/SEO data. | **CRITICAL** | **FIXED** — `PublicationDeploymentService` now builds the envelope via `BlogPublicationPayloadBuilder` and calls `createDraft` then `publish` when auto-publish is allowed. | `server-php/app/Libraries/Publishing/Jobs/PublicationDeploymentService.php`, `Publishing/Blog/BlogPublicationPayloadBuilder.php` |
| F-11 | Default worker cron only drained the `default` queue; `blog`/`publishing` jobs enqueued by the automation pipeline were never leased and sat pending indefinitely. | **CRITICAL** | **FIXED** — `reach:work --queue` now accepts a comma-separated list; `reach:work --queue=default,blog,publishing` drains all three from one process. Runbook corrected with an explicit worker-validation step. | `server-php/app/Commands/ReachWork.php`; runbook diff in [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md) |
| F-12 | Cron/dispatcher risk of inventing one article per run. | MEDIUM | **VERIFIED SAFE** — dispatcher only enqueues *eligible* existing work blocks; it does not create topic candidates or articles on its own. | `RoadmapOptimizerService`, `reach:blog-dispatch` |

## §11–§12 — Providers, Fact Verification

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-13 | Cross-provider review must fail to `requires_human` when only one provider is configured (self-review risk). | HIGH | **VERIFIED / test-proven** — `CROSS_REVIEW` handler detects same-provider generation+review and forces `requires_human=true`, transitioning to `INTERNAL_REVIEW` rather than auto-approving. Proven in the new E2E test (`testFullBlogAutomationPipelineEndToEnd`, step 9). | `WorkBlockService::executeCrossReview` |
| F-14 | Fact verification must fail closed on unsupported/uncorroborated statutory claims. | **CRITICAL** | **VERIFIED / test-proven** — negative test `testUnsupportedClaimsFailClosedAndBlockPublication` asserts an implausible statutory-rate claim is never marked publishable and drives the item to `CHANGES_REQUESTED`. | `WorkBlockService::executeFactVerify`, new E2E suite |
| F-15 | GA4 content-analytics connector was mock-only in production paths. | HIGH | **FIXED** — real, configurable `GoogleAnalyticsContentConnector` added with honest `DISABLED`/`MISCONFIGURED` states; `ConnectorProviderFactory` wired to select it. | `server-php/app/Libraries/Intelligence/Connectors/GoogleAnalyticsContentConnector.php`, `ConnectorProviderFactory.php` |

## §13 — Risk and Human Approval

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-16 | No version-checksum-bound approval; amending content did not invalidate a prior approval; no protected named-reviewer ("CA Rahul Gupta") attribution, despite an existing proven pattern in Community (`OfficialAnswerApprovalService`). | **CRITICAL** | **FIXED** — `BlogContentApprovalService` (new) implements checksum-bound approval, revocation, and `protectedReviewerName()` gated on registration + exact-version checksum match. Migrations: `reach_blog_content_approvals`, `reach_blog_protected_reviewers`. Negative tests proving free-text/unregistered reviewers cannot forge attribution and that amendment invalidates approval are in the new E2E suite (`testAmendingContentInvalidatesPriorApproval`, `testRevokedApprovalBlocksPublicationImmediately`, `testNamedReviewerAttributionRequiresProtectedRegistrationAndExactVersionMatch`). | `server-php/app/Libraries/Blog/BlogContentApprovalService.php` |

## §14–§17 — Storage, File Packaging, Public Publisher, Migration

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-17 | Public idempotency store 409'd on identical retries instead of comparing stored request checksum. | HIGH | **FIXED** | `includes/publisher/PublisherController.php`, `IdempotencyStore` (aicountly-com) |
| F-18 | Drafts were written directly under `published/` with no `staging → activate` step, no `archive/`, no `quarantine/`. | HIGH | **FIXED** — `PackageWriter` now stages, atomically activates, archives on replace, quarantines invalid packages. | `includes/publisher/PackageWriter.php` |
| F-19 | No real rollback wired to `previous_version_key`. | HIGH | **FIXED** (public side: `handleRestore`; Reach side: see F-09 above). | `includes/publisher/PublisherController.php::handleRestore` |
| F-20 | Hero/inline images were never ingested by the receiver. | HIGH | **FIXED** — `HeroImageFetcher` (new) + controller wiring to `PackageWriter`'s binary path. | `includes/publisher/HeroImageFetcher.php` |
| F-21 | `VALIDATE` did not run `PackageValidator`/MIME/checksum on verify/publish. | HIGH | **FIXED** | `includes/publisher/PackageValidator.php` |
| F-22 | `package_migrated` backfill flag dropped on subsequent `create`. | MEDIUM | **FIXED** | `includes/publisher/PublicationRegistry.php` |
| F-23 | `MockPublicSitePublisher::getVerification()` returned a hardcoded checksum, producing a false negative when `executeVerifyPublication` compared against the real computed checksum. | MEDIUM | **FIXED** — mock now stores and returns the actual payload checksum from `createDraft`/`updateDraft`/`publish`. | `server-php/app/Libraries/Publishing/Connector/MockPublicSitePublisher.php` |
| F-24 | New article bodies risked being stored complete in PostgreSQL for the published-package path. | **CRITICAL** | **VERIFIED FIXED** — the automation path stores relative storage keys + checksums; the full body for the published package lives in the file package on the public site, not in a `blog_posts.content`-style column for newly generated content. | `ContentVersionService`, publish payload builder |

## §18–§19 — Public Routes, SEO, Sitemap, Indexing

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-25 | Sitemap included `noindex` content, overstating what should be crawled. | HIGH | **FIXED** — `robots_directive` column (migration `009_blog_publications_robots_directive.pgsql.sql`) + `PublicationRegistry::listPublishedIndexable()`; `blogs-sitemap.php` filters on it. | `aicountly-com/database/migrations/009_*.sql`, `blogs-sitemap.php` |
| F-26 | `blog_article_json_ld` had no `publisher` (Organization) structured data. | LOW | **FIXED** | `includes/blog/Helpers.php` |
| F-27 | Indexing state must never be conflated with "published"/"in sitemap". | HIGH | **VERIFIED / test-proven** — `reach_blog_publication_profiles.indexing_status` is asserted `!= 'indexed'` immediately after going `LIVE` in the new E2E test; `CHECK_INDEXING` is a separate, later-scheduled block. | `WorkBlockService::executeUpdateSitemap`/`executeCheckIndexing`, E2E test |

## §22 — Feature Flags

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-28 | Flags must be enforced front **and** back, with safe defaults (auto-publish off, high-risk auto-publish impossible, file rendering off until verified, DB fallback on during transition). | HIGH | **VERIFIED** — `BlogFeatureFlags` gates every handler server-side; `BlogCommandCentreLayout`/nav gate client-side; defaults reviewed and confirmed safe (`BLOG_AUTO_PUBLISH_ENABLED` unset ⇒ off; high-risk auto-publish structurally impossible per F-07). | `server-php/app/Libraries/Blog/BlogFeatureFlags.php` |

## §23 — RBAC and Security

| # | Finding | Class | Status | Evidence |
|---|---|---|---|---|
| F-29 | Menu-hidden-but-API-reachable risk on BCC routes. | MEDIUM | **FIXED / verified** — `BlogCommandCentreLayout` now denies via `ForbiddenPage` (F-02); backend permission filters (`blog.view` etc.) independently gate the API regardless of frontend state. | `BlogCommandCentreLayout.jsx`, `Config/Permissions.php`, route filters |
| F-30 | Provider keys / HMAC secrets must never reach React payloads. | HIGH | **VERIFIED** — no provider credential or HMAC secret is serialized into any BCC/Publishing API response; signing happens server-side only (`HmacSigner`). | `server-php/app/Libraries/Publishing/Connector/*` |

## Out-of-scope items observed but not part of this report

Community-agent-runtime files (`CommunityAgentDispatchController`, `CommunitySchedule`, `includes/community/*`, related migrations `2026-07-31-210001`/`210002`/`220001` and `008_community_receiver_schema.pgsql.sql`) belong to a separate, concurrently-running Community audit on the same branch. They are unmodified by this engagement and excluded from the §30 blog-automation verdict.
