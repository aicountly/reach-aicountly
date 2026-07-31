# Blog Automation Inspection Report

**Date:** 2026-07-30  
**Source of truth:** executable code in three repositories  
**Inspection HEAD commits:**

| Repository | Path | Branch | Commit |
|---|---|---|---|
| Reach | `/Users/rahulgupta/reach-aicountly` | `main` | `44db5b352a00c6e817e06c4d95dd9da11585f0c5` |
| Public site | `/Users/rahulgupta/aicountly-com` | `main` | `2fdb187b96b924917956f7435b55b17b6b05c5f2` |
| Flow | `/Users/rahulgupta/flow-react-app` | `main` | `de581d912fd93b4aab70d2b1048466cb6adba4e0` |

---

## 1. Framework versions

### Reach (`server-php` + `web`)

| Layer | Version |
|---|---|
| PHP | `^8.2` |
| CodeIgniter 4 | `^4.6` (lock: 4.7.3) |
| Database | PostgreSQL (`aicountly_reach`) |
| React | `^19.0.0` |
| Vite | `^6.0.7` |
| react-router-dom | `^7.1.1` |
| Vitest | `^2.1.8` |

Spark commands found: `reach:schedule`, `reach:work`, `reach:import-taxonomy`.

### Public site (`aicountly-com`)

Plain PHP includes (no framework, no Composer runtime). PostgreSQL via PDO (`aicountly_community`). Manual SQL migrations under `database/migrations/`.

### Flow (`flow-react-app`)

Plain PHP API + React 19 / Vite 8. Default DB name `aicountly_flow` via `DB_*` env vars.

---

## 2. Existing content / blog systems in Reach

### Parallel systems (pre-consolidation)

1. **Legacy Marketing Blog** — `reach_blog_posts` + React `/blog*` + `BlogController`
2. **Unified Content Studio** — `reach_content_items` (`content_type` includes `blog`) + `/content*`
3. **Publishing Blogs** — `/publishing/blogs` + `reach_publication_deployments`

### Key tables (actual names)

| Concept in brief | Actual table |
|---|---|
| Content items | `reach_content_items` |
| Versions | `reach_content_versions` |
| Briefs | `reach_content_briefs` |
| Blog details | `reach_content_blog_details` |
| Legacy blogs | `reach_blog_posts` (+ `content_item_id`) |
| Approvals | `reach_approvals` |
| Schedules | `reach_content_schedules` |
| Sources | `reach_sources` |
| Claims | `reach_product_claims` |
| Publications | `reach_publication_deployments` (no `reach_publications`) |
| Jobs | `reach_jobs` |
| Topic clusters | `reach_topic_clusters` |
| Portfolio | **missing** |
| Work blocks | **missing** |
| Topic candidates / scores | **missing** |

---

## 3. Critical defects confirmed

### Publication queue schema mismatch

`PublicationDeploymentService` and `PublicationRetryService` insert `type` / `payload` into `reach_jobs`, but the schema and worker expect `job_type` / `payload_json`. No `PublicationJob` handler is registered in `JobHandlerRegistry`. Publication jobs cannot execute.

### Public publisher API missing

Reach client calls `/reach/v1/content/*` with HMAC headers. **No receiver exists** on `aicountly-com`.

### Flow writes public blogs

Flow's `BlogPostController` is the only in-repo writer of `blog_posts`. Public site reads `blog_posts` from `aicountly_community` and falls back to Flow public API when DB unavailable. Production env DB pointing must be verified in WHM runbook.

### Canonical URL mismatch vs brief

Live: `/blogs` listing + `/blog/{slug}` detail. Brief requires `/blogs/{slug}` canonical with 301 from `/blog/{slug}`.

### AI / intelligence gaps

- AI adapters: OpenAI (real) + mock only. No Gemini, Perplexity, or image adapters.
- Phase 8 Search Console / content analytics connectors: mock-only.
- Dashboard GA4: real when service account configured.

---

## 4. Navigation (Reach sidebar, pre-change)

Top-level sections include Marketing (with **Blog Management** → `/blog`), Content Studio, Publishing, AI Control Centre, Intelligence, Administration (Job Monitor), etc. No Blog Command Centre.

Publishing horizontal sub-nav: Blogs, Knowledge Base, Calendar, Deployments, Verifications, Connections, Readiness.

---

## 5. Public site blog surface

| Path | File | Notes |
|---|---|---|
| `/blogs` | `blogs.php` | Listing from DB |
| `/blog/{slug}` | `blog/post.php` | Detail; body from `blog_posts.content` |
| `/blog/media/...` | `blog/media.php` | Upstream image proxy |
| `/blog/health` | `blog/health.php` | Diagnostics |
| Sitemap / RSS / robots | — | **Absent** for blogs |
| Publisher API | — | **Absent** |
| Private file storage | — | **Absent** (`bloguploads` not referenced) |

---

## 6. Reusable modules (must not duplicate)

- Content Studio (`/content*`, `contentService`)
- Publishing blogs / calendar / deployments / verifications
- Approvals / Daily Approval Centre
- Knowledge topic clusters / sources / claims
- AI Control Centre (providers, routing, usage, budgets)
- Job Monitor / Worker Status
- Intelligence (deep-link; connectors mostly mock)
- Audit logs
- Existing `JobService` lease / SKIP LOCKED / backoff / dead-letter

---

## 7. Gap summary for foundation implementation

| Gap | Action in this pass |
|---|---|
| Missing inspection path in brief | This report + `docs/blog-automation/` |
| Blog Command Centre | New React section + menu mapping |
| Legacy parallel CRUD | Stage A–D migrate/redirect/deprecate |
| Queue mismatch | Fix enqueue + register handlers |
| Work blocks / portfolio / taxonomy | New migrations + services |
| Public receiver + file packages | New `includes/publisher` + `reach/v1` |
| Canonical `/blogs/{slug}` | Routes + 301 + SEO |
| DB body → file backfill | `blog:backfill-files` |
| Reach legacy → content items | `reach:blog-migrate-legacy` |
| Flow blog module | Remove write surface; deprecate table |
| Gemini / Perplexity / optimiser / live GSC | Scaffold flags/tables; defer full adapters |

---

## 8. Feature-flag inventory (target)

```
BLOG_COMMAND_CENTRE_ENABLED
BLOG_LEGACY_CREATE_DISABLED
BLOG_LEGACY_REDIRECT_ENABLED
BLOG_AUTOMATION_ENABLED
BLOG_ROADMAP_OPTIMIZER_ENABLED
BLOG_AI_GENERATION_ENABLED
BLOG_FACT_VERIFICATION_ENABLED
BLOG_IMAGE_GENERATION_ENABLED
BLOG_AUTO_PUBLISH_ENABLED
BLOG_FILE_RENDERING_ENABLED
BLOG_DB_BODY_FALLBACK_ENABLED
BLOG_PUBLIC_PUBLISHER_ENABLED
BLOG_SEARCH_CONSOLE_ENABLED
BLOG_GA4_ENABLED
```

Safe defaults: auto-publish off; AI generation off until keys; file rendering off until backfill verified; DB fallback on; high-risk auto-publish permanently prohibited in code.
