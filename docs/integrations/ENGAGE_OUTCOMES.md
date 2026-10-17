# Engage → Reach lead outcomes

Engage (engage.aicountly.org) tells Reach what happened to the leads Reach sent it: stage changes
(qualified, demo, proposal_sent, converted, lost) and confirmed payments. Reach stores each event,
matches it to the Reach lead, attributes it to a campaign / content / source, and reports
conversions and revenue (Leads → **Lead Outcomes**, **Analytics → Sales outcomes (Engage)**, and
`LeadOutcomeSummary::forPeriod()` for the daily report).

The other direction — Reach pushing a captured lead to Engage — is `EngageClient`
(`POST {ENGAGE_API_BASE_URL}/internal/reach/leads`, header `X-Portal-Token`); it now also carries the
seller referral code and UTM / landing data (see "Reach → Engage payload" below).

## Configuration (Reach `server-php/.env`)

| Variable | Meaning |
|---|---|
| `REACH_INBOUND_ENGAGE_TOKEN` | Shared secret Engage sends in `X-Engage-Token`. **At least 32 characters** (e.g. `openssl rand -hex 32`). Unset, empty or shorter than 32 → the endpoint answers **503** and nothing is stored. Compared with `hash_equals`. |

Set the same value on the Engage side (its outcome sender's Reach token, e.g. `REACH_OUTCOMES_TOKEN`
next to Engage's Reach base URL `https://reach.aicountly.org/api`). The Lead Outcomes and Analytics
pages show a warning while the token is not configured, and "No outcome has been received from Engage
yet" until the first event arrives — they never show estimated figures.

Migration: `2026-10-17-800001_CreateReachLeadOutcomes` (run `php spark migrate` as for every deploy).
It creates `reach_lead_outcomes` and adds to `reach_leads`: `ref_code`, `utm_source`, `utm_medium`,
`utm_campaign`, `utm_content`, `utm_term`, `landing_url`, `referrer_url`, `engage_stage`,
`engage_sales_status`, `engage_stage_at`.

## Endpoint

```
POST https://reach.aicountly.org/api/v1/inbound/engage/lead-outcomes
X-Engage-Token: <REACH_INBOUND_ENGAGE_TOKEN>
Content-Type: application/json

{"events": [{
  "event_id":      "eng-out-000123",            // required, unique per event, [A-Za-z0-9._:-], ≤100
  "lead_code":     "LD-000041",                 // Engage lead code (≤100)
  "reach_lead_id": 41,                          // reach_meta.reach_lead_id Reach sent, or null
  "email_sha256":  null,                        // sha256(lower(trim(email))) hex, or null
  "stage":         "converted",                 // required lower-case slug, see below
  "sales_status":  "won",                       // ≤64, optional
  "product":       "books",                     // ≤100, optional
  "value_inr":     "11800.00",                  // NUMERIC(14,2) string (number accepted), ≥0, or null
  "occurred_at":   "2026-10-17T10:15:00+05:30", // required ISO-8601 date-time (offset honoured)
  "seller_code":   "SLR-000123",                // or null
  "campaign_code": "REACH-7",                   // the campaign_code Reach sent, or null
  "source_portal": "reach.aicountly.org"
}]}
```

- At least one of `lead_code`, `reach_lead_id`, `email_sha256` is required. 1–500 events per request.
- **Stages.** `qualified`, `demo`, `proposal_sent`, `converted`, `lost` as in the contract. For a
  **confirmed payment** send `stage: "payment_confirmed"` (preferred) — or keep the lead's stage and set
  `sales_status: "payment_confirmed"` (or `"paid"`) — with the amount received in `value_inr`.
  Other slugs are stored but count only in `by_stage`.
- **Idempotent by `event_id`.** A repeat is answered `duplicate` and changes nothing (even if the body
  differs — give a corrected event a new `event_id`).

### Response

`200` `{"ok":true,"data":{"received":3,"accepted":2,"duplicate":0,"rejected":1,"failed":0,"results":[...]}}`

Each result: `{event_id, status, outcome_id?, lead_id, match, attribution?, error?}` where `status` is

| status | meaning | Engage outbox should |
|---|---|---|
| `accepted` | stored | mark delivered |
| `duplicate` | seen before; nothing changed | mark delivered |
| `rejected` | invalid (reason in `error`) — permanent | stop retrying; alert |
| `failed` | Reach could not store it | retry |

Other answers: `401` wrong/missing token, `503` token not configured, `422` body is not
`{"events":[…]}` / empty / more than 500 events (nothing stored), `500` with `details` = the same
result object when any event `failed` — the whole batch may be resent safely (stored events come back
`duplicate`). Retry `5xx` and network errors with backoff; never retry `401`/`422` unchanged.

## Matching and attribution

Matching (`match` / `reach_lead_outcomes.match_method`): `reach_lead_id` (a lead with that id exists)
→ `engage_lead_code` (earliest Reach lead with that code) → `email_sha256` → `unmatched` (stored, shown
on the page, counted by its lead code). A match also records the Engage code on the lead if it had none
and updates `reach_leads.engage_stage` / `engage_sales_status` — only when the event is not older than
the stage already recorded (late events never regress a lead).

Attribution (`attribution_method`), fixed at receipt:

1. `lead_campaign` — the lead's `campaign_id` (and `landing_page_id`).
2. `touchpoint` — the lead's latest attribution conversion link (`reach_attribution_conversion_links` →
   `reach_attribution_touchpoints`): campaign and `content_identity_id`. A landing-page lead also gets
   its landing page's content identity.
3. `campaign_code` — Engage's `campaign_code` of the form `REACH-<campaign id>` when that campaign exists.
4. `lead_source` — no campaign/content, but a source is known.
5. `unattributed`.

`source` = the lead's `utm_source`, else the touchpoint's `utm_source` / `channel`, else the lead's
`source_kind`, else `seller` when a seller code is on the event or lead.

## Reporting — `App\Libraries\Leads\LeadOutcomeSummary::forPeriod($from, $to, $tz = 'Asia/Kolkata')`

`$from` / `$to`: `Y-m-d` (whole days in `$tz`, both inclusive) or ISO date-times (from inclusive, to
exclusive). Counts by `occurred_at`. Returns `period`, `events`, `matched`, `unmatched`,
`conversions` (distinct leads with a `converted` event), `revenue_inr` (sum of `value_inr` on
payment-confirmed events), `converted_value_inr` (sum of `value_inr` on `converted` events — deal value,
not money received), `by_stage` (distinct leads per stage; all five contract stages plus
`payment_confirmed` always present), and `by_source`, `by_campaign`, `by_content`, `by_seller` (each
row: `events`, `conversions`, `revenue_inr`, `converted_value_inr`). Money is a 2-decimal string.

Staff APIs (JWT): `GET v1/leads/outcomes` (lead.view; filters `stage`, `match`, `lead_id`,
`campaign_id`, `from`, `to`, `search`), `GET v1/leads/outcomes/summary` (lead.view) and
`GET v1/analytics/outcomes` (analytics.view) — both summaries default to the last 30 IST days and add
`inbound_configured` and `last_received_at`.

## Reach → Engage payload (lead push)

The public capture endpoint (`POST v1/public/leads/capture`) now stores, from the form body:
`ref` (seller code, e.g. `SLR-000123`; upper-cased, `[A-Z0-9_-]`, ≤32), `utm_source`, `utm_medium`,
`utm_campaign`, `utm_content`, `utm_term` (flat, or nested `utm: {source, medium, …}`), `landing_url`
(or `page_url`) and `referrer` (or `referrer_url`) — http(s) only. Malformed values are dropped; the lead
is still captured. Forms should forward `?ref=` and `utm_*` from the landing URL.

`EngageClient` sends them on every push and retry:

```json
{ "...": "existing fields",
  "ref": "SLR-000123",
  "reach_meta": { "reach_lead_id": 41, "source_kind": "landing", "landing_page_id": 3,
                  "ref": "SLR-000123", "utm_source": "google", "utm_medium": "cpc",
                  "utm_campaign": "diwali-gst", "utm_content": "...", "utm_term": "...",
                  "landing_url": "https://www.aicountly.com/?ref=SLR-000123", "referrer_url": "https://www.google.com/" } }
```

Empty values are omitted. **Engage side:** resolve `reach_meta.ref` (or top-level `ref`) to
`lead.seller_id` (ignore unknown codes), keep the UTM / landing data on the lead, and echo
`reach_meta.reach_lead_id` back as `reach_lead_id` and the Reach `campaign_code` back in outcome events.
