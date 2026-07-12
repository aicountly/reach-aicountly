# Reach — Daily Marketing Pack

**Phase 2 — Unified Content Studio**

## Overview

The Daily Marketing Pack is a curated set of content items assigned to production slots for a given calendar day. It replaces ad-hoc daily planning with a structured, config-driven system.

---

## Database Tables

### `reach_daily_marketing_packs`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGSERIAL | |
| `uuid` | UUID | |
| `pack_date` | DATE | Unique per market+language |
| `market_id` | BIGINT FK | Phase 1 market |
| `language` | VARCHAR(8) | |
| `pack_status` | VARCHAR(32) CHECK | `draft`, `review_pending`, `approved`, `published`, `archived` |
| `admin_owner_id` | BIGINT FK | |
| `summary` | TEXT | |

### `reach_daily_marketing_pack_items`

| Column | Type | Notes |
|---|---|---|
| `pack_id` | BIGINT FK | |
| `content_item_id` | BIGINT FK NULLABLE | NULL = placeholder slot |
| `slot_type` | VARCHAR(64) | Content type of this slot |
| `is_placeholder` | BOOLEAN | True when no content assigned |
| `priority` | INT | Sort order within pack |

---

## Pack Configuration

Stored in `reach_settings` as key `daily_pack_config` (JSONB). Default structure:

```json
{
  "slots_per_day": {
    "blog": 2,
    "social_post": 5,
    "email": 1,
    "video_script": 1
  },
  "max_pending_backlog": 10,
  "risk_ceiling": "high",
  "reviewer_auto_assign": false
}
```

Config is read and updated via:
- `GET /api/v1/content/daily-packs/config`
- `PUT /api/v1/content/daily-packs/config`

---

## Pack Generation

`DailyMarketingPackService::generate()`:

1. Read `daily_pack_config` from `reach_settings`
2. For each slot type: query `reach_content_items` for items in `idea`/`draft`/`review_pending` status that match market/language/risk criteria
3. Skip items already in a pack for this date (duplicate prevention)
4. Skip items if `max_pending_backlog` would be exceeded
5. Create pack record + item records; unfilled slots become placeholder items (`is_placeholder = true`)

---

## Frontend (`DailyPackPage`)

- Lists packs by date on the left
- Selected pack shows slot cards via `DailyPackSlot` component
- Each slot card shows: content type, title, workflow status badge, review status, approval progress
- Placeholder slots show an "Assign" button that prompts for a content item ID
- Pack header shows: total slots, approved count, progress bar, missing-slot count
- Pack Config panel (admin/marketing_manager): edit `daily_pack_config` JSON

---

## Jobs

`DailyMarketingPackJob` is registered as `reach.daily_marketing_pack` and dispatched at 07:00 daily by `ReachSchedule`. It calls `DailyMarketingPackService::generate()` for tomorrow's date.

---

## Required Permissions

| Action | Permission |
|---|---|
| View packs | `daily_pack.view` |
| Generate pack | `daily_pack.create` |
| Manage slots/config | `daily_pack.manage` |
| Approve pack | `daily_pack.approve` |
