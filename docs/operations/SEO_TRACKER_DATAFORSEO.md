# SEO Tracker (DataForSEO) — setup

The SEO Tracker (Reach → SEO Command Centre → SEO Tracker) records real Google
India positions for the tracked keywords, for aicountly.com and every active
competitor in `reach_competitors`. Data comes from the DataForSEO SERP API,
Live / Advanced:

```
POST https://api.dataforseo.com/v3/serp/google/organic/live/advanced
Authorization: Basic base64(login:password)
[{"keyword": "...", "location_code": 2356, "language_code": "en", "device": "desktop", "depth": 100}]
```

Only `organic` items are counted; `rank_absolute` is stored. Without
credentials the page and the daily report say **Not configured** and nothing
is written — no position is ever estimated.

## Operator steps

1. Create an account at <https://app.dataforseo.com/> and add funds
   (Live SERP calls are pay-as-you-go; each response's `cost` is stored on
   `reach_seo_serp_checks.cost`, so actual spend is auditable).
2. **API Access** (dashboard → API settings) shows the **API login** (your
   account email) and the **API password** (a generated password — not your
   dashboard password).
3. In Reach: **Settings → AI & Automation → SEO data**
   - *DataForSEO login* = API login
   - *DataForSEO password* = API password (stored encrypted)
   - optional: *max keywords checked per day* (default 35 — one paid call
     each), *location code* (2356 = India), *language* (en), *depth* (100),
     *our domain* (aicountly.com), *SEO Tracker daily SERP checks* on/off.

   (Env fallback if the settings screen is not deployed yet:
   `DATAFORSEO_LOGIN=…`, `DATAFORSEO_PASSWORD=…` in `server-php/.env`.)
4. Check it: open SEO Tracker — status must read **READY**. Press **Run now**
   (needs `seo.manage`) or wait for the daily 06:00 IST run
   (`reach.seo_tracker`, enqueued by `reach:schedule`, `default` queue).
5. Verify: the keyword table fills with *Our position*, *vs 7d*, one column per
   competitor and the *Winner*; *Share of top 10* lists every domain.

## Behaviour

- Cost cap: at most `seo.tracker.max_keywords_per_day` calls per day; a
  keyword already checked successfully today is never re-bought; keywords not
  reached are first in line tomorrow (priority, then least recently checked).
- An auth (401 / 40100) or billing (402xx, e.g. 40210 insufficient funds)
  error stops the run after the first call and is stored as a `failed` check
  with the provider's message — never as "not ranking".
- Launch defaults were seeded by migration (insert-only): 10 competitors
  (Tally, Zoho Books, Vyapar, Busy, Marg ERP, myBillBook, ClearTax,
  QuickBooks, Khatabook, Swipe) and 31 keywords across Smart Books, Billing,
  Inventory, Notes, Drive and brand terms. Edit competitors under
  Intelligence → Competitors; add/enable/disable keywords on the SEO Tracker
  page.
