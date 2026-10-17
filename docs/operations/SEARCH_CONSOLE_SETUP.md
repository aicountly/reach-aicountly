# Google Search Console → Reach: setup and doctor

Reach reads Search Console with a **Google Cloud service account** (no user
OAuth, no Google SDK). Every row Google returns for the property is stored
site-wide (`reach_search_site_facts`, plus property-level daily totals in
`reach_search_site_daily`); rows whose page is a Reach-published blog are also
attached to that post (`reach_search_metric_facts`). The Search page
(SEO Command Centre → Search Console) shows the site-wide view by default and
the blog-mapped view beside it; the 08:00 IST daily report uses the site-wide
figures.

Nothing here is ever simulated: until the steps below are done the page and
the report say "Not configured".

## 1. Create the service account (Google Cloud Console, ~5 min)

1. Open <https://console.cloud.google.com/> and select (or create) a project,
   e.g. `aicountly-reach`.
2. **APIs & Services → Library** → search **"Google Search Console API"** →
   **Enable**.
3. **IAM & Admin → Service Accounts → Create service account**
   - Name: `reach-search-console`
   - Skip "Grant this service account access to project" (no project roles
     are needed) → **Done**.
4. Open the new account → **Keys → Add key → Create new key → JSON**. A file
   like `aicountly-reach-1a2b3c.json` downloads. It contains `client_email`
   (e.g. `reach-search-console@aicountly-reach.iam.gserviceaccount.com`) and
   `private_key`. Treat it as a password.

## 2. Give it read access to the property (Search Console)

The property is the **Domain property** `sc-domain:aicountly.com` (covers
http/https and every subdomain). A verified **Owner** of that property must do
this:

1. Open <https://search.google.com/search-console> → choose
   `aicountly.com` (Domain property).
2. **Settings → Users and permissions → Add user**.
3. Email: the `client_email` from the JSON file. Permission: **Restricted**
   (read-only is enough; **Full** also works). **Add**.

If the property list shows only URL-prefix properties
(`https://aicountly.com/`), either verify the Domain property via the DNS TXT
record Search Console offers, or use the exact URL-prefix string (with the
trailing slash) as `SEARCH_CONSOLE_SITE_PROPERTY` below. The string must match
character for character.

## 3. Put the key on the server and set env

```bash
# on the Reach server, as the cPanel user (outside public_html)
mkdir -p ~/secure && chmod 700 ~/secure
# upload the JSON as ~/secure/gsc-service-account.json, then:
chmod 600 ~/secure/gsc-service-account.json
```

`server-php/.env`:

```env
SEARCH_CONSOLE_ENABLED=true
SEARCH_CONSOLE_SITE_PROPERTY=sc-domain:aicountly.com
SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH=/home/<cpanel-user>/secure/gsc-service-account.json
# optional
SEARCH_CONSOLE_DATA_LAG_DAYS=3        # never ingest the newest N (unfinalised) days
BLOG_SEARCH_CONSOLE_ENABLED=true      # Blog Command Centre card reports live state
# must NOT be true in production:
# SEARCH_CONSOLE_USE_MOCK=false
```

The key file must be readable by both the CLI user (cron) and PHP-FPM/web.

## 4. Doctor, register, backfill (run on the server)

```bash
cd /home/<cpanel-user>/reach-aicountly/server-php
php spark migrate                               # creates the site-wide tables

php spark reach:search-console doctor           # status must be "configured"; shows client_email
php spark reach:search-console properties       # property_accessible must be true
php spark reach:search-console register         # stores the connection → connection_id
php spark reach:search-console health           # "healthy": true
php spark reach:search-console sync-identities  # map live blog URLs to posts

php spark reach:search-console backfill --days=90   # register a 90-day backfill
php spark reach:search-console backfill-run         # process all of it now (14-day slices)
php spark reach:search-console ingest               # the incremental window (last few days)
php spark reach:search-console status               # cursor + last run
php spark reach:search-console doctor               # site_wide_latest_date is now set
```

Without `backfill-run`, the daily 04:00 UTC `reach.search_console_ingest` job
processes one 14-day slice per day (90 days ≈ 7 days). After that the same job
keeps the last few days current; no extra cron line is needed — it is
enqueued by `reach:schedule` (see `REACH_WORKER_AND_CRON.md`).

Verify in Reach: **SEO Command Centre → Search Console** shows site-wide
clicks / impressions / CTR / position, totals by day, top queries, top pages
and position buckets.

## Reading `doctor`

| `status` / symptom | Meaning | Fix |
|---|---|---|
| `disabled` | `SEARCH_CONSOLE_ENABLED` is not true | set it, reload PHP |
| `misconfigured` — key path not set / file missing / not readable | the JSON is not where `.env` says, or permissions | fix the path; `chmod 600`, owned by the cPanel user |
| `misconfigured` — missing private_key or client_email | wrong file (e.g. an OAuth client file) | download a **service-account** JSON key |
| `health`: `AuthFailure` (401) | Google rejected the key (deleted/rotated key, clock skew) | create a new key; check server time (`date -u`) |
| `health`: `PermissionDenied` (403) / `property_accessible: false` | the service account is not a user on that exact property | step 2; compare `readable_properties` with `SEARCH_CONSOLE_SITE_PROPERTY` |
| ingest reports `0` rows | brand-new property, or the window is inside the ~3-day lag | wait 2–3 days; run `backfill-run` |
| `skipped` high, `ingested` 0 | rows are for pages that are not Reach blogs | normal — they are still in the site-wide view (`site_rows`) |
| `quota exceeded (HTTP 429)` | Search Console API quota | retry later; the job will pick up where the cursor is |

## What is stored

- `reach_search_site_facts` — (date, page, query) clicks, impressions, CTR,
  position for every row; device/country are `UNKNOWN`/`ZZZ` because those
  dimensions are not requested (they would multiply the row count).
- `reach_search_site_daily` — property-level totals per day × device. Google
  drops anonymised queries from query-level rows, so these are the figures
  that match the Search Console UI; summing the facts table reads lower.
- `reach_search_metric_facts` — the blog-mapped subset (unchanged).
- `reach_seo_rank_snapshots` — 05:00 UTC snapshot of the
  `reach_keyword_ideas` (status open / planned / in_progress) for the
  freshest ingested day.
