# Paid ads — Social & Ads → Ads (`/social/ads`)

Reach is **not connected** to the Google Ads, Meta or LinkedIn ads APIs. It does not place,
pause, edit or read ads. The owner places every campaign by hand in the platform's ads manager
and records it here; figures are typed in or imported from the ads manager's CSV export.
Nothing in Reach claims a sync that does not happen, and an unknown figure is shown as "—", never 0.

## Conventions (UTM)

| Platform (`platform`) | Ads manager | `utm_source` | `utm_medium` | Creative id prefix |
|---|---|---|---|---|
| Google Search (`google_search`) | Google Ads | `google` | `cpc` | `gs-` |
| YouTube (`youtube`) | Google Ads | `youtube` | `video` | `yt-` |
| Facebook + Instagram (`meta`) | Meta Ads Manager | `meta` | `paid_social` | `fb-` |
| LinkedIn (`linkedin`) | LinkedIn Campaign Manager | `linkedin` | `paid_social` | `li-` |

- `utm_campaign` = `soft-launch-2026-10` for the launch (lower-case slug).
- `utm_content` = the creative id, e.g. `gs-rsa-01`, `yt-reel-01`, `fb-reel-01`, `li-single-01`.
- Landing pages must be `https://aicountly.com/...`; the launch page is
  `https://aicountly.com/free-accounting-software`. aicountly.com carries the UTM set and the
  platform's click id (`gclid`, `gbraid`, `wbraid`, `fbclid`, `li_fat_id`) to the
  my.aicountly.com sign-up, which stores them with the new account.
- Keep Google Ads auto-tagging on (it adds `gclid`); Meta adds `fbclid`, LinkedIn `li_fat_id`.

The link builder on the page (and `POST v1/ads/utm-link`) produces these links; each registered
campaign lists its tracking links, one per creative.

## Launch plan (example budgets, not spend)

Owner's planned split for the soft launch, ≈ ₹2,000/day: Google Search ₹600, YouTube ₹300,
Meta (FB + IG) ₹300, LinkedIn ₹800. **Add launch plan** registers these as four `planned`
campaigns. They are plans: set each one's ads-manager name after placing it, then mark it `live`.
Spend only ever comes from entered or imported results.

## Daily results

- **By hand:** *Enter a day's results* — spend (₹), impressions, clicks, platform-reported
  conversions, sign-ups. An empty field is stored as unknown.
- **CSV import:** export with a daily breakdown, in INR, then *Preview* and *Import*.
  - Google Ads: Campaigns → Segment → Time → Day → Download → CSV (`Day, Campaign, Cost, Impr., Clicks, Conversions`).
  - Meta Ads Manager: Breakdown → By time → Day → Export table data → CSV
    (`Day` or `Reporting starts/ends`, `Campaign name`, `Amount spent (INR)`, `Impressions`, `Link clicks`, `Results`).
  - LinkedIn Campaign Manager: Analyze → Export → Campaign performance, time breakdown Daily
    (`Start Date (in UTC)`, `Campaign Name`, `Total Spent`, `Impressions`, `Clicks`, `Conversions`).
  - Rows are matched by the campaign's name in the ads manager (the *Name in the ads manager*
    field). Unmatched, multi-day, non-INR or unreadable rows are listed and not stored.
    Re-importing a file replaces that day's figures from the same source, so it changes nothing.
- **Sign-ups:** on the my.aicountly.com host run
  `php spark accounts:acquisition-report --since=2026-10-18 --out=signups.csv` (counts only, no
  personal data) and import it as *Aicountly sign-ups report*. Rows are matched by
  `utm_source` + `utm_campaign`; sign-ups without a campaign are reported as direct/organic.

## Leads

Leads come from Reach's own lead capture (`reach_leads`, which stores the UTM set the visitor
arrived with) and are attributed to a campaign by `utm_source` + `utm_campaign` (+ `utm_medium`)
within its dates, with a per-creative (`utm_content`) breakdown.

## API

| Route | Permission (any of) |
|---|---|
| `GET v1/ads/meta` | campaign.view, social.view |
| `GET v1/ads/summary?from=&to=` | campaign.view, social.view, dashboard.view |
| `GET/POST v1/ads/campaigns`, `GET/PUT/DELETE v1/ads/campaigns/{id}` | view: campaign.view, social.view · write: campaign.create/edit, social.create/edit |
| `POST v1/ads/campaigns/launch-plan` | campaign.create, social.create |
| `GET/PUT v1/ads/campaigns/{id}/results`, `DELETE v1/ads/results/{id}` | as above |
| `POST v1/ads/import` (multipart `file`, `format`, `dry_run`) | campaign.edit, social.edit |
| `POST v1/ads/utm-link` | campaign.view, social.view |

`GET v1/ads/summary` is what dashboard / plan views read: totals (spend, impressions, clicks,
platform conversions, leads, sign-ups, CTR, CPC, cost per lead, cost per sign-up), live and
planned daily budget, the launch plan, per-platform and per-campaign rows, days without results
for live campaigns, `api_connected: false` and a `source` note. Values are `null` when nothing
was entered.

Schema: migration `2026-10-18-310001_CreateReachAds` (`reach_ad_campaigns`, `reach_ad_results`).
