# Reach — Content Scheduling

**Phase 2 — Unified Content Studio**

## Overview

Content scheduling records a planned publication date and channel for an approved content item. Phase 2 does **not** publish content to external systems; scheduling is a workflow placeholder only.

---

## Table: `reach_content_schedules`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGSERIAL | |
| `uuid` | UUID | |
| `content_item_id` | BIGINT FK | Must be in `approved` workflow_status |
| `publication_target_id` | BIGINT FK | Target channel |
| `scheduled_at` | TIMESTAMPTZ | Planned publication time |
| `timezone` | VARCHAR(64) | IANA timezone |
| `schedule_status` | VARCHAR(32) CHECK | `pending`, `approved`, `executing`, `completed`, `cancelled`, `failed` |
| `approval_required` | BOOLEAN | If true, requires separate schedule approval |
| `job_id` | BIGINT FK NULLABLE | Linked `reach_jobs` record |
| `cancelled_at` | TIMESTAMPTZ | |
| `rescheduled_from_id` | BIGINT FK NULLABLE | Previous schedule |
| `created_by` | BIGINT FK | |

---

## Publication Targets

`reach_content_publication_targets` defines available channels:

Supported channels: `aicountly_website`, `youtube`, `linkedin`, `twitter_x`, `instagram`, `facebook`, `tiktok`, `email_newsletter`, `whatsapp_broadcast`, `sms_broadcast`, `community_forum`, `partner_site`, `press_release`

Each target has `target_url` (nullable), `target_config` (JSONB), and `is_active` flag.

---

## Scheduling Constraints

`ContentScheduleService::create()` enforces:

1. Content item must be in `workflow_status = 'approved'`
2. No existing active (non-cancelled) schedule for the same item + target combination
3. `scheduled_at` must be in the future

If `approval_required = true`, the schedule starts in `pending` status and requires a second approval action.

---

## Calendar Integration

`ContentScheduleService` also writes to `reach_content_calendar_items` (Phase 0 calendar). The calendar item uses:
- `ref_type = 'content_item'`
- `ref_id = content_item_id`
- `scheduled_date = DATE(scheduled_at)`
- `title = content item title`

This keeps the existing calendar view populated without a separate data entry step.

---

## Jobs

`ContentScheduleReadinessJob` (registered as `reach.content_schedule_readiness`) runs at 06:00 daily. It finds approved schedules with `scheduled_at <= now()` and transitions them to `executing` status, logging a `CONTENT_SCHEDULED` audit event.

---

## API Endpoints

| Method | Route | Description |
|---|---|---|
| GET | `/api/v1/content/items/:id/schedules` | List schedules for item |
| POST | `/api/v1/content/items/:id/schedules` | Create schedule |
| PUT | `/api/v1/content/items/:id/schedules/:sid` | Update schedule |
| DELETE | `/api/v1/content/items/:id/schedules/:sid` | Cancel schedule |

---

## Required Permissions

| Action | Permission |
|---|---|
| View schedules | `content_schedule.view` |
| Create/update schedule | `content_schedule.create` |
| Cancel schedule | `content_schedule.cancel` |
| Manage publication targets | `publication_target.manage` |
