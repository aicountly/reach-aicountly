# Blog Automation Foundation — Completion Deliverables

**Date:** 2026-07-30  
**Scope:** Foundation pass (Phases 0–7 of the approved plan). Deferred: Gemini/Perplexity/image adapters, roadmap optimiser scoring engine, live Search Console connector replacement.

---

## 1. Inspection report

- [docs/blog-automation/BLOG_AUTOMATION_INSPECTION_REPORT.md](BLOG_AUTOMATION_INSPECTION_REPORT.md)
- Alias path: [docs/blog-automation-inspection/BLOG_AUTOMATION_INSPECTION_REPORT.md](../blog-automation-inspection/BLOG_AUTOMATION_INSPECTION_REPORT.md)

## 2. Architecture decision records

| ADR | File |
|---|---|
| Canonical Content Studio engine | `adr/001-canonical-content-engine.md` |
| Reach sole writer / Flow retirement | `adr/002-reach-sole-writer-flow-retirement.md` |
| Plain-PHP publisher | `adr/003-plain-php-publisher.md` |
| Canonical `/blogs/{slug}` | `adr/004-canonical-blogs-slug.md` |
| Storage root derivation | `adr/005-storage-root-derivation.md` |
| Work blocks on `reach_jobs` | `adr/006-work-blocks-on-reach-jobs.md` |

## 3. React menu migration report

[REACT_MENU_MIGRATION_REPORT.md](REACT_MENU_MIGRATION_REPORT.md)

## 4. Legacy blog migration report

[LEGACY_BLOG_MIGRATION_REPORT.md](LEGACY_BLOG_MIGRATION_REPORT.md)  
Command: `php spark reach:blog-migrate-legacy`

## 5. Public content backfill report

[`aicountly-com/docs/blog-automation/PUBLIC_CONTENT_BACKFILL_REPORT.md`](../../../aicountly-com/docs/blog-automation/PUBLIC_CONTENT_BACKFILL_REPORT.md)  
Command: `php scripts/blog-backfill-files.php`

## 6. WHM/cPanel deployment runbook

[WHM_CPANEL_DEPLOYMENT_RUNBOOK.md](WHM_CPANEL_DEPLOYMENT_RUNBOOK.md)

## 7. Environment-variable templates

Updated:

- `reach-aicountly/server-php/.env.example` — all 14 `BLOG_*` flags + existing publisher keys  
- `aicountly-com/.env.example` — storage + publisher + rendering flags  
- Frontend: `VITE_BLOG_*` via `web/src/constants/blogFeatureFlags.js`

## 8. Database migration list

### Reach (`aicountly_reach`)

| Migration | Table / change |
|---|---|
| `2026-07-30-100001_CreateReachWorkBlocks` | `reach_work_blocks` |
| `2026-07-30-100002_CreateReachBlogPortfolioConfig` | `reach_blog_portfolio_config` (+ seed 45/35/20) |
| `2026-07-30-100003_ExtendReachContentBlogDetailsForPortfolio` | portfolio taxonomy columns |
| `2026-07-30-100004_CreateReachBlogLegacyMigrationMap` | `reach_blog_legacy_migration_map` |

### Public (`aicountly_community`)

| Migration | Table |
|---|---|
| `003_blog_publications.pgsql.sql` | `blog_publications` |
| `004_blog_publisher_nonces.pgsql.sql` | nonces + idempotency |

### Flow

| Migration | Notes |
|---|---|
| `002_deprecate_blog_posts.sql` | COMMENT ON TABLE; **does not drop** |

## 9. New routes and APIs

### Reach React

`/blog-command-centre/**` (overview, roadmap, pipeline, verification, publishing, seo, indexing, analytics, operations, settings)

Legacy redirects: `/blog*`, `/blogs/manage`, `/marketing/blogs`

### Reach API

- `GET v1/blog-command-centre/overview`
- `GET/PUT v1/blog-command-centre/settings/portfolio`
- settings endpoints for automation/rules (see `BlogCommandCentreController`)

### Public

- `/reach/v1/*` signed publisher API  
- `/blogs/{slug}` canonical article  
- `/blog/{slug}` → 301 `/blogs/{slug}`  
- `/blogs-sitemap.xml`, `/blogs-feed.xml`, `/robots.txt`  
- `/blogs/assets/{id}/{file}`

## 10. New job handlers

| job_type | Handler |
|---|---|
| `reach.publication` | `PublicationJob` |
| `reach.blog_work_block` | `BlogWorkBlockJob` |

Enqueue repaired to use `JobService` (`job_type` / `payload_json`).

## 11. Existing components reused

Content Studio lists/briefs/versions, Publishing blogs/calendar/deployments/verifications, Approvals, Job Monitor, AI usage/health/routing, Audit logs, Intelligence deep links, Knowledge topic clusters.

## 12. Legacy components deprecated

- Reach Marketing Blog Management sidebar entry removed; `/blog*` redirected  
- Reach `BlogController::store` 403 when `BLOG_LEGACY_CREATE_DISABLED`  
- Flow blog controller/pages/routes/sidebar Content section removed  
- Flow `blog_posts` table deprecated (not dropped)

## 13. Components removed (Flow)

- `BlogPostController.php`, `BlogPostModel.php`  
- `BlogPostsPage.jsx`, `BlogPostEditorPage.jsx`, `blogPostService.js`

## 14–18. Tests / lint / build / diff (executed locally 2026-07-30)

| Check | Result |
|---|---|
| Reach Vitest | **86 files, 327 passed** |
| Reach PHPUnit | **1213 tests, 3672 assertions, 0 failed, 118 skipped** (Feature DB skips without TEST_DB) |
| aicountly-com PHPUnit | **14 tests, 20 assertions, OK** |
| Reach `npm run lint` | **0 errors**, 4 warnings (1 new BCC context export warning; 2 pre-existing unused Icon) |
| Reach `npm run build` | **OK** (vite production build) |
| `php -l` public publisher files | **OK** (all clean) |
| `git diff --check` (reach, aicountly-com, flow) | **OK** (exit 0) |

## 19. Remaining credentials

- `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` / `SIGNING_KEY` (generate matching pair)  
- OpenAI key already used by Reach AI; Gemini/Perplexity/image keys deferred  
- Search Console / GA4 credential references for blog-scoped intelligence  

## 20–22. Remaining WHM / cPanel / deployment actions

See runbook §6 checklist. Nothing was executed against live WHM/cPanel/production. No git push.

## 23. Risks

- SEO transition on 301 flip  
- Flow deploy lag vs Reach/public  
- Enabling file rendering before backfill verify  
- Clock skew on HMAC  

## 24. Rollback

See runbook §9.

## 25. Production activation checklist

See runbook §6.

---

## End-to-end flow status (honest)

| Stage | Status in this pass |
|---|---|
| Roadmap topic → score → brief | Scaffolded work-block types + portfolio; optimiser deferred |
| Research → AI draft → brief compare | Work blocks + OpenAI path exist; new adapters deferred; AI gen flag default off |
| Fact verification / images | Flags + block types; adapters deferred (fail-closed for verification when enabled without provider) |
| Editorial / SEO / approval / schedule | Reused Content Studio + Publishing + Approvals |
| Queue dispatch | **Fixed** — `JobService` + `PublicationJob` registered |
| Signed publisher API | **Implemented** on public site |
| Private file package | **Implemented** |
| Public `/blogs/{slug}` + sitemap | **Implemented** |
| Analytics-ready | Dashboard cards honest DISABLED/NO DATA; live GSC still mock |

Full-body article storage for new publications goes to private file packages (relative keys in `blog_publications`); DB body retained as fallback for legacy rows.
