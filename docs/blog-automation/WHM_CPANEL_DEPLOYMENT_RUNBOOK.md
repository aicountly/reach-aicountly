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

# Daily topic discovery — 00:15 Asia/Kolkata (before optimiser). Needs approved Knowledge clusters.
# If server clock is UTC: 45 18 * * *
15 0 * * * cd /path/to/server-php && /usr/bin/php spark reach:blog-discover-topics --limit=10 >> writable/logs/blog-discover.log 2>&1

# Daily roadmap optimiser — 00:30 Asia/Kolkata.
# The command refuses to run outside 00:00-00:59 IST unless --force is passed, so
# it is safe even if the server clock is not Asia/Kolkata. UTC hosts: 0 19 * * *
30 0 * * * cd /path/to/server-php && /usr/bin/php spark reach:blog-optimize-roadmap >> writable/logs/blog-optimizer.log 2>&1

# Existing worker (must keep running) — CRITICAL: reach:work only drains the queue(s)
# named in --queue. Blog automation jobs are enqueued on the 'blog' and 'publishing'
# queues (see App\Libraries\Blog\WorkBlockService::enqueueEligibleBatch and
# App\Libraries\Publishing\Jobs\PublicationDeploymentService::enqueuePublicationJob).
# A worker started with only "--queue=default" (as originally shipped) NEVER
# processes those jobs — they sit "queued" forever. --queue accepts a
# comma-separated list so one worker cron line drains all three (+ community):
* * * * * cd /path/to/server-php && /usr/bin/php spark reach:work --queue=default,blog,publishing,community --once --limit=20 >> writable/logs/worker.log 2>&1
* * * * * cd /path/to/server-php && /usr/bin/php spark reach:schedule >> writable/logs/schedule.log 2>&1
*/5 * * * * cd /path/to/server-php && /usr/bin/php spark community:schedule >> writable/logs/community-schedule.log 2>&1
```

After deploy (API keys already in `.env`):

```bash
php spark reach:ai-seed-catalog
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

Reach reads Search Console with a **service account** — a robot Google identity
with its own email address. There is no OAuth consent screen, no browser
redirect and no refresh token to expire: the server signs a JWT with the
service account's private key and exchanges it for a short-lived access token
on every call. Nothing needs re-authorising later.

Two identities are involved and they are easy to confuse:

| | Where it lives | What it is for |
|---|---|---|
| The **property** (`aicountly.com`) | Search Console | The site whose figures you want |
| The **service account** | Google Cloud + the Reach server | The robot that reads them |

The property covers the **public site** (`aicountly.com`, where blogs are
served). The key file goes on the **Reach** cPanel account (`reach.aicountly.org`),
because Reach is what performs the ingestion. Do not put the key on the
`aicountlycom` account.

#### 1a. GOOGLE CLOUD — create the service account and its key

Do this while signed in as a Google account that can create GCP projects. It
does **not** have to be the same account that owns the Search Console property.

1. Open <https://console.cloud.google.com> → project picker → **New Project**.
   Name it something durable, e.g. `aicountly-reach`. (An existing project is
   fine — no need to create one per integration.)
2. With that project selected, go to **APIs & Services → Library**, search for
   **Google Search Console API**, open it and press **Enable**.
   The API's service name is `searchconsole.googleapis.com`. This is the only
   API Reach needs; do not enable the Indexing API for this.
3. Go to **IAM & Admin → Service Accounts → Create service account**.
   - *Service account name*: `reach-search-console`
   - *Description*: `Read-only Search Console ingestion for Reach`
   - Press **Create and continue**.
4. **Skip the "Grant this service account access to project" step.** Press
   **Continue**, then **Done**. This step trips people up: Reach needs **no**
   GCP IAM role at all. Search Console permissions are granted inside Search
   Console itself (step 1b), not through Google Cloud IAM. Adding a project
   role here grants nothing useful and widens the account's reach.
5. Open the service account you just created and copy its **email**. It looks
   like `reach-search-console@aicountly-reach.iam.gserviceaccount.com`. This is
   the `client_email` Reach reports in diagnostics, and the address you will
   paste into Search Console.
6. Go to the **Keys** tab → **Add key → Create new key → JSON → Create**.
   The browser downloads a `.json` file **once**. Google keeps no copy of the
   private key — if it is lost, delete the key and create a new one.

Treat that file exactly like a password. It grants read access to the property
for anyone holding it. Do not email it, paste it into a chat, commit it, or
store it in the Reach database — Reach deliberately stores only the *env var
name* pointing at it, never its contents.

> If **Create new key** is greyed out, an organisation policy
> (`constraints/iam.disableServiceAccountKeyCreation`) is blocking key creation.
> A Google Workspace admin has to grant an exception for the project.

#### 1b. SEARCH CONSOLE — grant the service account access

Do this signed in as an **owner** of the `aicountly.com` property; only owners
can add users.

1. Open <https://search.google.com/search-console> and select the property.
2. Note its exact form — you will need it verbatim later:
   - a **Domain** property shows as `aicountly.com` → the env value is
     `sc-domain:aicountly.com`
   - a **URL-prefix** property shows as `https://aicountly.com/` → the env value
     is `https://aicountly.com/`, **including the trailing slash**
3. **Settings → Users and permissions → Add user**.
4. Paste the service account email from step 1a.5.
5. Permission: **Full**. (**Restricted** is enough for reading Performance data
   if you prefer least privilege — Reach only ever requests the read-only scope
   `webmasters.readonly` and never writes.) Press **Add**.

There is no invitation to accept — a service account cannot click a link. The
grant is effective immediately.

> If the property is Domain-verified but blogs are served from a subdomain or a
> different host, add the service account to whichever property actually
> receives the traffic. Reach queries exactly one property string.

#### 1c. REACH CPANEL — install the key file

Upload the JSON to the Reach account, outside the document root. SFTP or the
cPanel File Manager both work; the commands below assume SSH.

```bash
# REACH CPANEL (as the cPanel user, not root)
mkdir -p ~/secure
chmod 700 ~/secure
# upload the downloaded file to ~/secure/gsc.json, then:
chmod 400 ~/secure/gsc.json

# Confirm it parses and is not truncated by the transfer
php -r 'var_dump(array_keys(json_decode(file_get_contents(getenv("HOME")."/secure/gsc.json"), true)));'
# expect: type, project_id, private_key_id, private_key, client_email, ...
```

Then confirm it is genuinely not web-reachable:

```bash
realpath ~/secure/gsc.json | grep public_html && echo "STOP — inside the docroot"
curl -sS -o /dev/null -w '%{http_code}\n' https://reach.aicountly.org/secure/gsc.json
# expect 404 (or 403) — anything else means it is exposed
```

Two host-specific things to check:

- **Which user PHP runs as.** On cPanel with PHP-FPM or suPHP, both the web
  requests and cron run as the cPanel user, so `chmod 400` owned by that user is
  readable by both. If PHP runs as `nobody` (mod_php/DSO), `400` will be
  unreadable from the web and you need `chmod 440` plus `chgrp nobody`. You do
  not have to guess — `reach:search-console doctor` (step 6) reports
  `file_readable` from whichever context you run it in.
- **`open_basedir`.** If the account has it set, the key must sit under a
  permitted prefix. Anywhere inside `/home/<cpanel_user>` normally qualifies.

#### 1d. Set the environment

`SEARCH_CONSOLE_SITE_PROPERTY` must match step 1b.2 **character for character** —
`sc-domain:aicountly.com` and `https://aicountly.com/` are different properties
to Google, and a mismatch surfaces as `PermissionDenied` rather than "not found".

CodeIgniter re-reads `.env` on every request, so no PHP restart is needed; if
the host runs OPcache in a mode that caches beyond the request, reloading
PHP-FPM is harmless.

Set:

```env
SEARCH_CONSOLE_ENABLED=true
BLOG_SEARCH_CONSOLE_ENABLED=true
SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH=/home/<cpanel_user>/secure/gsc.json
SEARCH_CONSOLE_SITE_PROPERTY=sc-domain:aicountly.com
SEARCH_CONSOLE_DATA_LAG_DAYS=3
SEARCH_CONSOLE_USE_MOCK=false
```

5. Apply the migration that makes the fact table upsertable against live data
   (widens `country` to alpha-3 and removes the NULLs that broke the unique key):

```bash
cd /home/<cpanel_user>/reach/server-php
php spark migrate
```

6. Diagnose before doing anything else. This makes no writes and no ingest:

```bash
php spark reach:search-console doctor
```

   It reports each flag, the credential file state, the service-account
   `client_email`, and a single `next_step` naming exactly what to fix.

7. Confirm Google actually grants access to the property, then register the
   connection and run the first health check:

```bash
php spark reach:search-console properties   # what the service account can read
php spark reach:search-console register     # creates the stored connection from .env
php spark reach:search-console health       # live authenticated check
```

   If `properties` comes back empty, step 1b has not taken effect — the service
   account is authenticated but has no grant on the property. `properties` is
   the single most useful command here: it lists what Google will actually let
   this service account read, so comparing it against
   `SEARCH_CONSOLE_SITE_PROPERTY` settles any "is it the grant or the string?"
   question outright.

8. Map live blog URLs, then pull the first data:

```bash
php spark reach:search-console sync-identities
php spark reach:search-console ingest
php spark reach:search-console backfill --days=90   # optional history
```

   `sync-identities` is not optional. Search Console reports against URLs, and
   facts are stored against a content identity; a post with no identity has its
   rows recorded as *unmapped* and discarded. The ingest job runs this itself
   before every pull, so newly published posts self-register from then on.

9. Confirm the state on **Overview → Search**. The card distinguishes `DISABLED`,
   `MISCONFIGURED` (with the precise reason), `MOCK`, and `CONNECTED`, and now also
   reports how many facts have actually landed in the last 28 days and the freshest
   day ingested. It never shows fabricated figures — if the property returns no rows,
   the batch is empty and the warning explains why.

   **A `CONNECTED` card with `facts (28d) 0` is a real state, not a bug**: credentials
   are fine and nothing has been ingested yet. Run step 8, or wait for the nightly job.

`SEARCH_CONSOLE_DATA_LAG_DAYS` matters: Search Console finalises data on a 2–3 day
lag, and the connector clamps the requested end date so partial days are never
ingested as complete. The newest 2–3 days will always be absent by design.

#### Ongoing operation

`reach.search_console_ingest` is enqueued daily at 04:00 by `ReachSchedule`, one
hour ahead of `reach.seo_snapshot` at 05:00, which aggregates exactly the rows it
writes. Each run ingests the rolling incremental window for every enabled `gsc`
connection and advances one 14-day slice of any active backfill.

Verify it is flowing from the UI at **Intelligence → Search**, which shows the
connector state, recent ingestion runs, and any unmapped URLs; the same actions
(health check, ingest, backfill, sync identities) are available there as buttons.

#### Troubleshooting

Every message below is emitted verbatim by the connector, so you can match on
the text. Run `php spark reach:search-console doctor` first — it names most of
these without contacting Google at all.

| What you see | What it means | Fix |
|---|---|---|
| `SEARCH_CONSOLE_ENABLED is false.` | The connector is switched off. | Set it `true` in `server-php/.env`. |
| `SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH is not set.` | Env var empty. | Point it at the absolute path from step 1c. |
| `Service-account key file does not exist at the configured path.` | Wrong path, or the upload landed elsewhere. | `ls -l` the path as the cPanel user. |
| `Service-account key file is not readable by the web/CLI user.` | Permissions or `open_basedir`. | See the PHP-user note in step 1c. |
| `...missing private_key or client_email.` | Truncated or wrong JSON — often an OAuth *client* JSON rather than a *service account* key. | Re-download from **Keys → Add key → JSON** on the service account. |
| `SEARCH_CONSOLE_SITE_PROPERTY is not set.` | Env var empty. | Copy the property string exactly as step 1b.2. |
| `Google rejected the service-account credentials.` (401) | Key deleted/disabled, or the project's clock-skew tolerance exceeded. | Confirm the key still exists in GCP; check server time with `timedatectl`. |
| `Service account is authenticated but has no access to <property>.` (403) | Auth works; the grant or the property string is wrong. | Run `properties` and compare against the env value character for character. |
| `properties` returns `[]` | No property grants at all. | Redo step 1b as a property **owner**. |
| `google_search_console: quota exceeded (HTTP 429).` | Rate limited. | Transient; the daily job retries. Avoid repeated manual backfills. |
| `Requested window is entirely inside the ... data lag.` | You asked for days Google has not finalised. | Expected. Widen the range or wait. |
| Card reads `CONNECTED`, `facts (28d)` is `0` | Credentials fine, nothing ingested yet. | Run step 8, or wait for 04:00. |
| `ingest` reports rows `skipped` and few/none ingested | Search Console URLs match no content identity. | Run `sync-identities`; then check **Intelligence → Search → Unmapped URLs** — usually the live canonical URL differs from the deployment record. |
| Card reads `MOCK` | `SEARCH_CONSOLE_USE_MOCK=true`. | Set it `false`. Figures until then are simulated. |

Rotating the key later: create the new key in GCP, upload it, update
`SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH` (or overwrite the file in place), run
`reach:search-console health`, and only then delete the old key in GCP. The
Search Console grant is attached to the service account, not to the key, so it
survives rotation untouched.

#### Which flag does what

| Flag | Effect if false |
| --- | --- |
| `SEARCH_CONSOLE_ENABLED` | Connector refuses every call; the ingest job skips and no facts are ever written. |
| `BLOG_SEARCH_CONSOLE_ENABLED` | Blog Command Centre Overview card reads `DISABLED` regardless of the connector's real state. |
| `SEARCH_CONSOLE_USE_MOCK=true` | Simulated figures are served instead of real ones; the card reads `MOCK`. |

Set the first two to `true` together. Setting only the blog flag makes the card
claim `CONNECTED` while nothing ingests.

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
