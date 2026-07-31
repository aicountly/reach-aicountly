# 08 — Deployment Actions (Environment / Credential / Production-Operation items)

Everything in this document is an item that **no repository change can satisfy**. Nothing here was executed — per instruction, no live WHM/cPanel action, no production secret, and no production data operation was performed during this engagement.

## 1. WHM / root actions

| Action | Command (for the operator to run) |
|---|---|
| Create private storage directory outside `public_html` | `mkdir -p /home/aicountlycom/bloguploads/{staging,published,archive,quarantine}` |
| Ownership | `chown -R aicountlycom:aicountlycom /home/aicountlycom/bloguploads` |
| Permissions | `chmod -R 750 /home/aicountlycom/bloguploads` |
| Verify path is not web-reachable | `namei -l /home/aicountlycom/bloguploads` — confirm no ancestor directory is inside `public_html` |
| Disk quota check | `quota -u aicountlycom` |
| Backup inclusion | Add `/home/aicountlycom/bloguploads` to the existing cPanel backup job scope |

## 2. `aicountly.com` cPanel — environment variables

Set via cPanel "Setup Node.js/PHP App" env editor or `.env` (never commit):

```
BLOG_CPANEL_USER=aicountlycom
BLOG_STORAGE_BASE=/home
BLOG_STORAGE_FOLDER=bloguploads
BLOG_STORAGE_ROOT=            # leave blank to use the resolved default above
```

Plus the public-receiver HMAC key pair matching Reach's `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY`/`AICOUNTLY_PUBLIC_SITE_KEY_ID` (see §3 below — both sides must share the same secret).

## 3. Reach cPanel — environment variables (credentials remaining)

All of the following are **unset placeholders in `server-php/.env.example`** and must be filled with real production values by the operator — none were fabricated or guessed during this engagement:

| Variable | Purpose |
|---|---|
| `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` | Bearer credential for the public-site publisher API |
| `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY` | HMAC-SHA256 shared secret (must match the public site's configured key) |
| `AICOUNTLY_PUBLIC_SITE_KEY_ID` | Key identifier sent in `X-Reach-Key-Id` (default `reach-v1`, may be kept) |
| `REACH_PUB_MOCK` | **Must be `false` (or unset) in production** — confirm it is not left `true` from testing |
| `AI_OPENAI_API_KEY`, `AI_GEMINI_API_KEY`, `AI_PERPLEXITY_API_KEY` | Provider adapters (`ARTICLE_GENERATOR`/`EDITORIAL_REVIEWER`/`FACT_VERIFIER` routing) |
| `REACH_AI_MOCK` | **Must be `false` (or unset) in production** |
| `SEARCH_CONSOLE_CREDENTIAL_REFERENCE`, `SEARCH_CONSOLE_USE_MOCK=false` | Real GSC adapter |
| `CONTENT_ANALYTICS_CREDENTIAL_REFERENCE`, `CONTENT_ANALYTICS_SERVICE_ACCOUNT_KEY_PATH`, `CONTENT_ANALYTICS_PROPERTY_ID`, `CONTENT_ANALYTICS_USE_MOCK=false` | New `GoogleAnalyticsContentConnector` (Pass 4) |
| `CONTENT_ANALYTICS_ENABLED`, `SEARCH_CONSOLE_ENABLED` (or equivalent flags) | Must be explicitly turned on; default is honestly `DISABLED` |

Blog feature flags to review before go-live (defaults reviewed and confirmed safe by this audit — do not change these defaults without a deliberate decision):

```
BLOG_COMMAND_CENTRE_ENABLED=true        # turn on to expose the BCC
BLOG_LEGACY_CREATE_DISABLED=true        # keep true — blocks new legacy records
BLOG_LEGACY_REDIRECT_ENABLED=true
BLOG_AUTOMATION_ENABLED=                # decide per rollout plan
BLOG_ROADMAP_OPTIMIZER_ENABLED=
BLOG_AI_GENERATION_ENABLED=
BLOG_FACT_VERIFICATION_ENABLED=true     # keep true — publication fails closed without it
BLOG_IMAGE_GENERATION_ENABLED=false     # keep off until reviewed
BLOG_AUTO_PUBLISH_ENABLED=false         # keep off — safe default, verified this audit
BLOG_FILE_RENDERING_ENABLED=false       # keep off until backfill --verify is clean
BLOG_DB_BODY_FALLBACK_ENABLED=true      # keep on during transition
BLOG_PUBLIC_PUBLISHER_ENABLED=true
```

## 4. Worker / cron install (root or Reach cPanel, per existing convention)

```cron
30 0 * * * cd /path/to/server-php && /usr/bin/php spark reach:blog-optimize-roadmap >> writable/logs/blog-optimizer.log 2>&1
* * * * * cd /path/to/server-php && /usr/bin/php spark reach:work --queue=default,blog,publishing --once --limit=20 >> writable/logs/worker.log 2>&1
* * * * * cd /path/to/server-php && /usr/bin/php spark reach:schedule >> writable/logs/schedule.log 2>&1
```

**Validation step (run once manually after deploy, before relying on cron):**

```bash
php spark reach:work --queue=default,blog,publishing --once --limit=1
# then:
psql -c "SELECT queue, status, count(*) FROM reach_jobs GROUP BY queue, status;"
```
Confirm `blog` and `publishing` rows do not accumulate indefinitely in `pending`/`reserved`.

## 5. PostgreSQL

```bash
# Reach
cd server-php && php spark migrate

# Public site
psql -d aicountly_prod -f database/migrations/009_blog_publications_robots_directive.pgsql.sql
```

Both are `IF NOT EXISTS`-guarded and safe to run against an already-migrated database. **Not executed in this engagement** — this sandbox has no `pdo_pgsql` extension and no reachable Postgres instance (see [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md)). Run the full Reach PHPUnit suite (`./vendor/bin/phpunit`) again *after* migrating in an environment with a real database — the 126 currently-skipped tests, including the new 30-step E2E acceptance test, must all pass before considering this ready for controlled deployment.

## 6. Backfill (public-site legacy body → file package)

```bash
php includes/publisher/backfill.php --dry-run --report=backfill-dry-run.json
# review the report, then:
php includes/publisher/backfill.php --batch-size=50 --verify
```

Not run against production data in this engagement (no snapshot available, explicitly out of scope). Run `--dry-run` first and inspect the report before any real batch; keep `BLOG_FILE_RENDERING_ENABLED=false` until `--verify` is clean.

## 7. Rollback procedure (if activation causes problems)

1. Set `BLOG_AUTO_PUBLISH_ENABLED=false` and `BLOG_AUTOMATION_ENABLED=false` immediately — in-flight work blocks finish or fail safely; no new work is dispatched (kill-switch behavior verified by code inspection of `BlogFeatureFlags` gating in every handler).
2. For an individual published article: use BCC → Publishing → Rollback (`PublicationRollbackService::rollback()`, fixed this session to genuinely revert to the previous version and stay live) or → Emergency Unpublish (`PublicationRollbackService::unpublish()`) to take it down entirely.
3. Database: `php spark migrate:rollback` reverses the four new Reach migrations if needed (each has a `down()`); the public-site migration `009` is additive-only (a new nullable-defaulted column) and safe to leave in place even if rolled back at the application level.
4. Code: `git revert` on the `audit/blog-automation-acceptance` branch, or simply do not merge/deploy it.

## 8. Production activation order (recommended)

1. Apply migrations (both repos).
2. Set Reach + public-site environment variables (§2–3), with `REACH_AI_MOCK=false`, `REACH_PUB_MOCK=false`.
3. Install/validate cron + worker (§4).
4. Run backfill `--dry-run` (§6); review report.
5. Turn on `BLOG_COMMAND_CENTRE_ENABLED`, `BLOG_PUBLIC_PUBLISHER_ENABLED`.
6. Turn on `BLOG_AUTOMATION_ENABLED`/`BLOG_ROADMAP_OPTIMIZER_ENABLED`/`BLOG_AI_GENERATION_ENABLED` for a controlled pilot (single portfolio stream) before full rollout.
7. Leave `BLOG_AUTO_PUBLISH_ENABLED=false` until several human-reviewed publish cycles have been observed.
8. Re-run the full Reach PHPUnit suite against the now-live database and confirm all 126 previously-skipped tests pass (§5 above) before declaring controlled deployment complete.
