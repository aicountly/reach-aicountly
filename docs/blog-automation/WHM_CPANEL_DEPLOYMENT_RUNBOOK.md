# WHM / cPanel Deployment Runbook — Blog Automation

**Do not execute live WHM or cPanel commands from Cursor agents.**  
This document is the operator checklist for humans with root / cPanel access.

Every action is tagged with an owner lane:

| Tag | Who |
|---|---|
| CURSOR/CODE | Already implemented in git; deploy the build |
| WHM ROOT | WHM / SSH root |
| AICOUNTLY.COM CPANEL | `aicountlycom` account |
| REACH CPANEL | Reach account hosting `reach.aicountly.org` |
| POSTGRESQL | DBA / root with DB access |
| REACH UI | Operator in Reach Blog Command Centre |

---

## 1. WHM ROOT — private storage

Target directory:

```text
/home/aicountlycom/bloguploads
```

Suggested commands (placeholders — confirm ownership/UID on the host):

```bash
# WHM ROOT
mkdir -p /home/aicountlycom/bloguploads/{staging,published,archive,quarantine}
chown -R aicountlycom:aicountlycom /home/aicountlycom/bloguploads
chmod 750 /home/aicountlycom/bloguploads
chmod 750 /home/aicountlycom/bloguploads/{staging,published,archive,quarantine}

# Verify path components
namei -l /home/aicountlycom/bloguploads

# Confirm not under public_html
realpath /home/aicountlycom/bloguploads | grep -v public_html

# Disk quota / PHP open_basedir / disable_functions review
# (panel-specific — document actual values after inspection)

# Ensure backups include /home/aicountlycom/bloguploads
# Ensure cron PHP binary path is known: which php && php -v
```

Verify:

- Directory is absolute and outside `public_html`
- Writable by the PHP user that runs `aicountly.com`
- Not web-exposed (no Alias / no symlink into docroot)

---

## 2. POSTGRESQL — migration order

### Reach DB (`aicountly_reach`)

```bash
# REACH CPANEL / POSTGRESQL
cd ~/reach-aicountly/server-php   # or deploy path
php spark migrate
```

New migrations in this release:

- `2026-07-30-100001_CreateReachWorkBlocks`
- `2026-07-30-100002_CreateReachBlogPortfolioConfig`
- `2026-07-30-100003_ExtendReachContentBlogDetailsForPortfolio`
- `2026-07-30-100004_CreateReachBlogLegacyMigrationMap`

Backup before migrate:

```bash
pg_dump -Fc -d aicountly_reach -f ~/backups/aicountly_reach_pre_blog_$(date +%Y%m%d).dump
```

### Public site DB (`aicountly_community`)

```bash
# AICOUNTLY.COM CPANEL / POSTGRESQL
cd ~/public_html   # or site root
php scripts/migrate.php
# dry-run first:
php scripts/migrate.php --dry-run
```

Applies:

- `003_blog_publications.pgsql.sql`
- `004_blog_publisher_nonces.pgsql.sql`

Backup:

```bash
pg_dump -Fc -d aicountly_community -f ~/backups/aicountly_community_pre_blog_$(date +%Y%m%d).dump
```

### Flow DB (verify pointer)

```bash
# WHM ROOT / FLOW CPANEL
# Confirm production Flow .env DB_NAME:
# Code default is aicountly_flow; public site uses aicountly_community.
grep '^DB_NAME=' /path/to/flow/server-php/.env

# Optional deprecation comment (only if blog_posts exists in that DB):
psql -d "$DB_NAME" -f server-php/database/migrations/002_deprecate_blog_posts.sql
```

**Do not drop `blog_posts`.**

Rollback (PostgreSQL): restore dump; for Reach, `php spark migrate:rollback` only if tested on staging first.

---

## 3. AICOUNTLY.COM CPANEL — env, deploy, backfill

### Environment

Add to `.env` (placeholders):

```env
BLOG_CPANEL_USER=aicountlycom
BLOG_STORAGE_BASE=/home
BLOG_STORAGE_FOLDER=bloguploads
BLOG_STORAGE_ROOT=
BLOG_FILE_RENDERING_ENABLED=false
BLOG_DB_BODY_FALLBACK_ENABLED=true
BLOG_PUBLIC_PUBLISHER_ENABLED=false
AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN=<generate>
AICOUNTLY_PUBLIC_SITE_SIGNING_KEY=<generate>
AICOUNTLY_PUBLIC_SITE_KEY_ID=reach-v1
AICOUNTLY_PUBLIC_SITE_MAX_SKEW_SECONDS=300
REACH_PUBLISHER_ALLOWED_IPS=<reach-egress-ip-optional>
```

### Deploy (CURSOR/CODE → rsync)

Deploy application code including `includes/publisher/`, `reach/v1/`, `.htaccess`, `blog/article.php`, `robots.txt`, sitemap/feed scripts.

Remove stale static `public_html/blogs/` directory if present (blocks dynamic listing).

### Commands

```bash
php scripts/migrate.php
php scripts/blog-backfill-files.php --dry-run --report
php scripts/blog-backfill-files.php --batch-size=50 --report
# After validation:
# BLOG_FILE_RENDERING_ENABLED=true
```

### Health

```bash
curl -sS https://aicountly.com/reach/v1/health
curl -sSI https://aicountly.com/blog/sample-slug   # expect 301 → /blogs/sample-slug
curl -sS https://aicountly.com/blogs-sitemap.xml | head
```

### Rollback

- Set `BLOG_PUBLIC_PUBLISHER_ENABLED=false`
- Set `BLOG_FILE_RENDERING_ENABLED=false` (DB body fallback remains)
- Restore previous release tree
- Optionally restore DB dump

---

## 4. REACH CPANEL — env, deploy, cron, worker

### Environment

```env
BLOG_COMMAND_CENTRE_ENABLED=true
BLOG_LEGACY_CREATE_DISABLED=true
BLOG_LEGACY_REDIRECT_ENABLED=true
BLOG_AUTOMATION_ENABLED=false
BLOG_ROADMAP_OPTIMIZER_ENABLED=false
BLOG_AI_GENERATION_ENABLED=false
BLOG_FACT_VERIFICATION_ENABLED=false
BLOG_IMAGE_GENERATION_ENABLED=false
BLOG_AUTO_PUBLISH_ENABLED=false
BLOG_PUBLIC_PUBLISHER_ENABLED=false
BLOG_SEARCH_CONSOLE_ENABLED=false
BLOG_GA4_ENABLED=false

AICOUNTLY_PUBLIC_SITE_BASE_URL=https://aicountly.com
AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN=<same-as-public>
AICOUNTLY_PUBLIC_SITE_SIGNING_KEY=<same-as-public>
AICOUNTLY_PUBLIC_SITE_KEY_ID=reach-v1
REACH_PUB_MOCK=false
```

### Deploy

```bash
# API
cd server-php && php spark migrate && composer install --no-dev
# Web
cd web && npm ci && npm run build
# Point docroot / reverse proxy at web/dist + server-php public as usual
```

### Cron (Asia/Kolkata window also enforced in app)

```cron
# Every 30 minutes during allowed hours (server TZ should be Asia/Kolkata OR command converts)
0,30 0-8,19-23 * * * cd /path/to/server-php && /usr/bin/php spark reach:blog-dispatch >> writable/logs/blog-dispatch.log 2>&1

# Daily roadmap optimiser — 00:30 Asia/Kolkata.
# The command refuses to run outside 00:00-00:59 IST unless --force is passed, so
# it is safe even if the server clock is not Asia/Kolkata.
30 0 * * * cd /path/to/server-php && /usr/bin/php spark reach:blog-optimize-roadmap >> writable/logs/blog-optimizer.log 2>&1

# Existing worker (must keep running) — CRITICAL: reach:work only drains the queue(s)
# named in --queue. Blog automation jobs are enqueued on the 'blog' and 'publishing'
# queues (see App\Libraries\Blog\WorkBlockService::enqueueEligibleBatch and
# App\Libraries\Publishing\Jobs\PublicationDeploymentService::enqueuePublicationJob).
# A worker started with only "--queue=default" (as originally shipped) NEVER
# processes those jobs — they sit "queued" forever. --queue accepts a
# comma-separated list so one worker cron line drains all three:
* * * * * cd /path/to/server-php && /usr/bin/php spark reach:work --queue=default,blog,publishing --once --limit=20 >> writable/logs/worker.log 2>&1
* * * * * cd /path/to/server-php && /usr/bin/php spark reach:schedule >> writable/logs/schedule.log 2>&1
```

**Worker validation after deploy:** run `php spark reach:work --queue=default,blog,publishing --once --limit=1` manually once and confirm the `worker.start` log line lists all three queues, then check `SELECT queue, status, count(*) FROM reach_jobs GROUP BY queue, status;` — `blog` and `publishing` rows must not accumulate indefinitely in `pending`/`reserved` status.

If the server clock is UTC, schedule the optimiser at `0 19 * * *` instead — IST is
UTC+5:30 year-round with no daylight saving, so 19:00 UTC is 00:30 IST the next day.
The in-app guard remains the authority either way. Verify it first:

```bash
# Should print a skip event with reason "outside_preferred_hour" during the day
php spark reach:blog-optimize-roadmap

# Score without persisting anything
php spark reach:blog-optimize-roadmap --force --dry-run
```

### Legacy migrate (Reach)

```bash
php spark reach:blog-migrate-legacy --dry-run --report
php spark reach:blog-migrate-legacy --batch-size=50 --report
```

### AI provider keys

All keys are read from the environment only — never from the database. Leaving a key
blank keeps that adapter permanently inactive (`isConfigured()` returns false), so a
partially configured environment degrades rather than failing.

| Key | Used by |
|---|---|
| `AI_OPENAI_API_KEY` | OpenAI text + `openai_image` |
| `AI_OPENAI_IMAGE_MODEL` | OpenAI image model override (default `gpt-image-1`) |
| `AI_GEMINI_API_KEY` | Gemini text + `gemini_image` |
| `AI_GEMINI_IMAGE_MODEL` | Gemini image model (default `gemini-2.5-flash-image`) |
| `AI_PERPLEXITY_API_KEY` | Fact verification (Sonar) |
| `AI_PERPLEXITY_DEFAULT_MODEL` | Default `sonar-pro` |
| `AI_PERPLEXITY_HEALTHCHECK_ALLOW_SPEND` | Leave `false`; Perplexity health checks cost tokens |

Verify which adapters the environment considers usable from
**Blog Command Centre → Overview → AI Ops**, which reads the adapters directly.

### Fact verification (fail-closed)

```env
BLOG_FACT_VERIFICATION_ENABLED=true
BLOG_VERIFICATION_PASS_THRESHOLD=0.95
BLOG_AUTHORITATIVE_SOURCE_HOSTS=incometax.gov.in,gst.gov.in,mca.gov.in,rbi.org.in,sebi.gov.in,egazette.gov.in
```

If verification is enabled but Perplexity is unconfigured or errors, the result is
`unavailable` — never `passed`. High-risk claims additionally require at least one
source on the authoritative host list or they are reported as unsupported.

### Google Search Console (live connector)

1. Create a Google Cloud service account and download its JSON key.
2. Upload the key **outside the document root**, e.g. `/home/<cpanel_user>/secure/gsc.json`,
   then `chmod 400` it and make sure both the web user and the cron user can read it.
3. In Search Console, add the service account's `client_email` as a user on the property.
4. Set:

```env
SEARCH_CONSOLE_ENABLED=true
BLOG_SEARCH_CONSOLE_ENABLED=true
SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH=/home/<cpanel_user>/secure/gsc.json
SEARCH_CONSOLE_SITE_PROPERTY=sc-domain:aicountly.com
SEARCH_CONSOLE_DATA_LAG_DAYS=3
SEARCH_CONSOLE_USE_MOCK=false
```

5. Confirm the state on **Overview → Search**. The card distinguishes `DISABLED`,
   `MISCONFIGURED` (with the precise reason), `MOCK`, and `CONNECTED`. It never shows
   fabricated figures — if the property returns no rows, the batch is empty and the
   warning explains why.

`SEARCH_CONSOLE_DATA_LAG_DAYS` matters: Search Console finalises data on a 2–3 day
lag, and the connector clamps the requested end date so partial days are never
ingested as complete.

### GA4

`BLOG_GA4_ENABLED` plus the existing GA4 credential references. Unchanged in this pass.

---

## 5. REACH UI — first activation

1. Open **Blog Command Centre → Settings → Blog Portfolio** — confirm 45/35/20.
2. Settings → Automation Window, Publication Rules, Risk Rules — leave auto-publish **off**.
3. Overview → AI Ops — confirm the intended text and image adapters read `CONFIGURED`.
4. Roadmap → Optimizer — confirm the weights total 100, then run
   `php spark reach:blog-optimize-roadmap --force --dry-run` and check the run appears.
5. Publishing → Connections — health-check public site.
6. Keep high-risk content on human approval (enforced in code).

---

## 6. Production activation checklist

1. [ ] WHM storage directory created, owned, not web-exposed  
2. [ ] DB backups taken  
3. [ ] Reach migrations applied  
4. [ ] Public migrations applied  
5. [ ] Matching HMAC secrets on Reach + public  
6. [ ] `/reach/v1/health` returns ok with publisher flag on  
7. [ ] Legacy Reach migrate dry-run reviewed  
8. [ ] Public backfill dry-run reviewed; verify sample slugs  
9. [ ] Enable `BLOG_FILE_RENDERING_ENABLED` only after verify  
10. [ ] Confirm `/blog/{slug}` → 301 `/blogs/{slug}`  
11. [ ] Sitemap + robots live  
12. [ ] Flow blog UI gone; Flow writes impossible  
13. [ ] Cron + worker validated in Job Monitor  
14. [ ] Auto-publish remains disabled  

---

## 7. Remaining credentials / actions

| Item | Owner |
|---|---|
| Service token + signing key generation | REACH + AICOUNTLY.COM CPANEL |
| Gemini / Perplexity / image API keys | REACH CPANEL (deferred adapters) |
| Search Console property + SA JSON | REACH CPANEL |
| GA4 property linkage for blogs | REACH CPANEL |
| Confirm Flow production `DB_NAME` | WHM ROOT |
| Live WHM `open_basedir` / backup inclusion | WHM ROOT |
| DNS / CDN cache purge after 301 flip | AICOUNTLY.COM CPANEL |

---

## 8. Risks

- SEO volatility from `/blog/{slug}` → `/blogs/{slug}` 301s  
- Dual-write window if Flow deploy lags Reach/public  
- File rendering enabled before backfill verify → empty bodies  
- HMAC clock skew if servers diverge  

## 9. Rollback instructions

1. Disable `BLOG_PUBLIC_PUBLISHER_ENABLED` and `BLOG_AUTOMATION_ENABLED` on Reach.  
2. Disable publisher + file rendering on public; keep DB fallback.  
3. Restore previous code trees.  
4. Restore DB dumps if schema rollback required.  
5. Re-enable Flow blog module **only** from a tagged pre-retirement commit if emergency content editing is needed (not recommended).
