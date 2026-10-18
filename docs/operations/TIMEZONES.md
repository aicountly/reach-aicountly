# Time zones in Reach

**Every time a person reads is Indian Standard Time (Asia/Kolkata), labelled "IST".
Everything stored or exchanged by machines is UTC.**

| Layer | Zone | Notes |
|-------|------|-------|
| PHP clock | UTC | `App::$appTimezone = 'UTC'`; `date('Y-m-d H:i:s')` writes UTC wall-clock time. |
| Database | UTC | Nearly every column is `TIMESTAMPTZ`; Postgres returns it with an offset (`2026-10-18 01:04:00+00`). The session zone must be UTC so the zone-less strings PHP writes mean UTC — check with `SHOW timezone;`, fix with `ALTER DATABASE <db> SET timezone TO 'UTC';`. |
| API / JSON | UTC | Values pass through as stored. Unchanged contract for routines and other callers. |
| Web UI | IST | `web/src/utils/formatDate.js` — the only place dates are formatted. |
| Emails, notifications, CLI text | IST | `App\Libraries\Support\DisplayTime`. JSON log lines keep `ts` (UTC ISO) and add `ts_ist`. |
| Cron / timetables | UTC, documented as both | `reach:schedule` hours are UTC; each one is written as "08:00 UTC (13:30 IST)". IST = UTC + 05:30, no daylight saving. |

## How the web reads an API value

- An offset or `Z` is honoured: `2026-10-18 01:04:00+00`, `…T01:04:00Z`, `…+05:30`.
- **A zone-less `YYYY-MM-DD HH:MM[:SS]` is UTC.** It used to be read as browser-local time, which is
  why the Job Monitor showed `18-10-2026 01:04` for a job that ran at 06:34 IST. We chose this over
  rewriting every API response to ISO because it fixes all screens in one place and leaves the API
  unchanged.
- A date-only `YYYY-MM-DD` (publish date, report date, due date) is a calendar date and is never
  moved to another day.

Helpers: `formatDateTime` → `18-10-2026 06:34 IST`, `formatDate` → `18-10-2026`, `formatTime` →
`06:34 IST`, `formatRelative` → `12h ago`, `toDateInputValue` / `todayInputValue` (IST day, wire
format), `isPast`.

## Date/time inputs

A `datetime-local` value is what the operator typed in IST. `fromDateTimeInputValue('2026-10-18T10:00')`
sends `2026-10-18T10:00:00+05:30`. That is unambiguous for Postgres, and its date prefix is still the
IST day, which `ContentScheduleService` uses for the calendar entry. `toDateTimeInputValue` puts a
stored value back into the input in IST. Date-only inputs (`<input type="date">`) are calendar dates
and go out unchanged.

## Changing the zone

`ui.timezone` (Settings → AI & Automation → Display; default `Asia/Kolkata`) is read by
`DisplayTime` and returned as `ui_timezone` by `GET v1/me` and the sign-in response. The web applies
it at sign-in. Stored values are not touched, so changing it only changes what people see. The daily
report always uses IST: its day is defined as the 24 hours to 08:00 IST.
