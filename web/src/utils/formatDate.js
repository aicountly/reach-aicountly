/**
 * THE single source of truth for how dates and times are rendered in the UI.
 *
 * House format is dd-mm-yyyy, times are 24-hour HH:mm, and every time is
 * shown in ONE zone — Indian Standard Time (Asia/Kolkata) unless the
 * `ui.timezone` setting says otherwise (`setDisplayTimeZone`, applied at
 * sign-in from /v1/me). It is never the viewer's browser zone: an operator
 * on a UTC laptop and one in Pune must read the same "18-10-2026 06:34 IST".
 *
 * How API values are read (docs/operations/TIMEZONES.md):
 *   - Storage is UTC. TIMESTAMPTZ columns arrive with an offset
 *     ("2026-10-18 01:04:00+00"), ISO strings with Z or an offset — both are
 *     absolute and parsed as such.
 *   - A zone-less "YYYY-MM-DD HH:MM[:SS]" is UTC: the server clock
 *     (App::$appTimezone) and the database session are UTC, so that is what
 *     a naive value from PHP's date() or a TIMESTAMP column means. Reading it
 *     as browser-local time is what made the Job Monitor show UTC as if local.
 *   - A date-only "YYYY-MM-DD" is a calendar date (publish date, report
 *     date) — no instant, so it is never shifted into another day.
 *
 * Machine-facing values are deliberately NOT the display format: `<input
 * type="date">` and API query params take ISO yyyy-mm-dd (`toDateInputValue`),
 * `<input type="datetime-local">` takes "yyyy-mm-ddTHH:mm" in the display zone
 * (`toDateTimeInputValue`), and what the operator types goes back to the API
 * as ISO 8601 with the zone's offset (`fromDateTimeInputValue`).
 */

export const DEFAULT_TIME_ZONE = 'Asia/Kolkata';

/** Short labels for zones whose Intl abbreviation is unhelpful ("GMT+5:30"). */
const ZONE_LABELS = { 'Asia/Kolkata': 'IST', 'Asia/Calcutta': 'IST', UTC: 'UTC', 'Etc/UTC': 'UTC', GMT: 'UTC' };

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
  'August', 'September', 'October', 'November', 'December'];

const PAD = (n) => String(n).padStart(2, '0');

let displayTimeZone = DEFAULT_TIME_ZONE;
let partsFormatter = null;

function isValidTimeZone(tz) {
  if (typeof tz !== 'string' || tz.trim() === '') return false;
  try {
    new Intl.DateTimeFormat('en-GB', { timeZone: tz.trim() });
    return true;
  } catch {
    return false;
  }
}

/**
 * Switch the display zone (the `ui.timezone` setting). An unknown or empty
 * zone keeps IST rather than falling back to the browser's zone.
 * Returns the zone now in effect.
 */
export function setDisplayTimeZone(tz) {
  const next = isValidTimeZone(tz) ? tz.trim() : DEFAULT_TIME_ZONE;
  if (next !== displayTimeZone) {
    displayTimeZone = next;
    partsFormatter = null;
  }
  return displayTimeZone;
}

export function getDisplayTimeZone() {
  return displayTimeZone;
}

/** "IST" for Asia/Kolkata, "UTC" for UTC, else the Intl short name. */
export function timeZoneLabel(tz = displayTimeZone) {
  if (ZONE_LABELS[tz]) return ZONE_LABELS[tz];
  try {
    const part = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'short' })
      .formatToParts(new Date())
      .find((p) => p.type === 'timeZoneName');
    return part?.value || tz;
  } catch {
    return tz;
  }
}

// ── parsing ──────────────────────────────────────────────────────────────

/** Date-only API values ("2026-08-03") carry no timezone. */
const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Every datetime shape the API emits: ISO "T" or Postgres' space separator,
 * optional seconds and fraction, and an optional zone — Z, "+00" (Postgres'
 * hour-only offset), "+0530" or "+05:30". No zone means UTC.
 */
const DATE_TIME = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,9}))?)?\s*(Z|UTC|[+-]\d{2}(?::?\d{2})?)?$/i;

const asDate = (d) => (Number.isNaN(d.getTime()) ? null : d);

/** UTC milliseconds for wall-clock fields; years 0-99 are taken literally. */
function utcMs(y, mo, d, h = 0, mi = 0, s = 0, ms = 0) {
  const date = new Date(Date.UTC(2000, mo - 1, d, h, mi, s, ms));
  date.setUTCFullYear(y);
  return date.getTime();
}

function offsetMinutes(zone) {
  if (!zone || /^(z|utc)$/i.test(zone)) return 0;
  const sign = zone[0] === '-' ? -1 : 1;
  const digits = zone.slice(1).replace(':', '');
  return sign * (Number(digits.slice(0, 2)) * 60 + Number(digits.slice(2, 4) || 0));
}

/**
 * The value as an absolute instant (Date), or null when unusable — so
 * callers render a fallback rather than "Invalid Date". A date-only value
 * becomes UTC midnight of that date; use `formatDate` / `toDateInputValue`
 * to render it, which keep it on its own calendar day.
 */
export function toDate(value) {
  if (value === null || value === undefined || value === '') return null;
  if (value instanceof Date) return asDate(new Date(value.getTime()));
  if (typeof value === 'number') return asDate(new Date(value));

  const raw = String(value).trim();
  if (!raw) return null;

  const dateOnly = raw.match(DATE_ONLY);
  if (dateOnly) {
    const [, y, m, d] = dateOnly.map(Number);
    return asDate(new Date(utcMs(y, m, d)));
  }

  const dt = raw.match(DATE_TIME);
  if (dt) {
    const [, y, mo, d, h, mi, s = '0', frac = '0', zone] = dt;
    const ms = Number(frac.padEnd(3, '0').slice(0, 3));
    const wall = utcMs(Number(y), Number(mo), Number(d), Number(h), Number(mi), Number(s), ms);
    return asDate(new Date(wall - offsetMinutes(zone) * 60000));
  }

  // Anything else (RFC 2822 and the like): the engine's own parser.
  return asDate(new Date(raw));
}

/** True when the value is a moment before `now`; false when missing/unreadable. */
export function isPast(value, now = Date.now()) {
  const d = toDate(value);
  return d !== null && d.getTime() < Number(now);
}

// ── zone arithmetic ──────────────────────────────────────────────────────

function formatter() {
  if (!partsFormatter) {
    partsFormatter = new Intl.DateTimeFormat('en-GB', {
      timeZone: displayTimeZone,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
      hourCycle: 'h23',
    });
  }
  return partsFormatter;
}

/** Wall-clock fields of an instant in the display zone. */
function zonedParts(date) {
  const out = {};
  for (const p of formatter().formatToParts(date)) {
    if (p.type !== 'literal') out[p.type] = Number(p.value);
  }
  if (out.hour === 24) out.hour = 0; // pre-hourCycle engines write midnight as 24
  return { y: out.year, m: out.month, d: out.day, h: out.hour, mi: out.minute, s: out.second };
}

/** Display-zone offset from UTC, in minutes, at an instant. */
function zoneOffsetAt(ms) {
  const p = zonedParts(new Date(ms));
  const asUtc = utcMs(p.y, p.m, p.d, p.h, p.mi, p.s);
  return Math.round((asUtc - Math.floor(ms / 1000) * 1000) / 60000);
}

/** Calendar fields of a value: literal for a date-only string, display zone otherwise. */
function calendarParts(value) {
  if (typeof value === 'string') {
    const dateOnly = value.trim().match(DATE_ONLY);
    if (dateOnly) {
      const [, y, m, d] = dateOnly.map(Number);
      return { y, m, d, dateOnly: true };
    }
  }
  const date = toDate(value);
  return date ? zonedParts(date) : null;
}

const ymd = (p) => `${String(p.y).padStart(4, '0')}-${PAD(p.m)}-${PAD(p.d)}`;
const dmy = (p) => `${PAD(p.d)}-${PAD(p.m)}-${String(p.y).padStart(4, '0')}`;
const withZone = (text, zone) => (zone ? `${text} ${timeZoneLabel()}` : text);

// ── display ──────────────────────────────────────────────────────────────

/** dd-mm-yyyy — the house date format, in the display zone. */
export function formatDate(value, fallback = '—') {
  const p = calendarParts(value);
  return p ? dmy(p) : fallback;
}

/**
 * dd-mm-yyyy HH:mm IST — 24-hour, display zone. `{ zone: false }` drops the
 * suffix where a column header already says IST. A date-only value has no
 * time, so it renders as its date alone.
 */
export function formatDateTime(value, fallback = '—', { zone = true } = {}) {
  const p = calendarParts(value);
  if (!p) return fallback;
  if (p.dateOnly) return dmy(p);
  return withZone(`${dmy(p)} ${PAD(p.h)}:${PAD(p.mi)}`, zone);
}

/** HH:mm IST — 24-hour, display zone. */
export function formatTime(value, fallback = '—', { zone = true } = {}) {
  const p = calendarParts(value);
  if (!p || p.dateOnly) return fallback;
  return withZone(`${PAD(p.h)}:${PAD(p.mi)}`, zone);
}

/**
 * "just now", "5m ago", "12h ago", "3d ago" (or "in 2h" for the future);
 * beyond 30 days, the date. Computed between instants, so the zone of the
 * value never skews it.
 */
export function formatRelative(value, { now = Date.now(), fallback = '—' } = {}) {
  const d = toDate(value);
  if (!d) return fallback;
  const diff = Number(now) - d.getTime();
  const abs = Math.abs(diff);
  if (abs < 45_000) return 'just now';
  const units = [['d', 86_400_000], ['h', 3_600_000], ['m', 60_000]];
  if (abs >= 30 * 86_400_000) return formatDate(value, fallback);
  const [unit, size] = units.find(([, ms]) => abs >= ms) ?? units[2];
  const n = Math.max(1, Math.floor(abs / size));
  return diff >= 0 ? `${n}${unit} ago` : `in ${n}${unit}`;
}

/** The display zone's current month (+ offset months), as { year, month } (month 0-11). */
export function displayMonth(offset = 0, now = Date.now()) {
  const p = zonedParts(new Date(Number(now)));
  const index = p.y * 12 + (p.m - 1) + offset;
  return { year: Math.floor(index / 12), month: ((index % 12) + 12) % 12 };
}

/** "October 2026" — month heading, locale-independent. */
export function formatMonthYear(year, month) {
  return `${MONTHS[month] ?? ''} ${year}`.trim();
}

// ── wire formats ─────────────────────────────────────────────────────────

/**
 * yyyy-mm-dd for `<input type="date">` and API query params — the wire
 * format, never a display format. An instant becomes its display-zone date.
 * Returns '' so a controlled input stays controlled when the value is missing.
 */
export function toDateInputValue(value, fallback = '') {
  const p = calendarParts(value);
  return p ? ymd(p) : fallback;
}

/** Today in the display zone, in wire format — for input defaults and query params. */
export function todayInputValue() {
  return toDateInputValue(new Date());
}

/** "yyyy-mm-ddTHH:mm" in the display zone, for `<input type="datetime-local">`. */
export function toDateTimeInputValue(value, fallback = '') {
  const p = calendarParts(value);
  if (!p) return fallback;
  return `${ymd(p)}T${PAD(p.h ?? 0)}:${PAD(p.mi ?? 0)}`;
}

/**
 * What the operator typed into a datetime-local input, read as display-zone
 * wall-clock time, as ISO 8601 with that zone's offset:
 * "2026-10-18T10:00" → "2026-10-18T10:00:00+05:30". Unambiguous for the API
 * and Postgres, and its date prefix is still the IST day. '' / unreadable → null.
 */
export function fromDateTimeInputValue(local) {
  const m = String(local ?? '').trim().match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!m) return null;
  const [, y, mo, d, h, mi, s = '0'] = m;
  const wall = utcMs(Number(y), Number(mo), Number(d), Number(h), Number(mi), Number(s));
  let offset = zoneOffsetAt(wall);
  const second = zoneOffsetAt(wall - offset * 60000);
  if (second !== offset) offset = second; // the wall time sits across a DST change
  const sign = offset < 0 ? '-' : '+';
  const abs = Math.abs(offset);
  return `${y}-${mo}-${d}T${h}:${mi}:${PAD(Number(s))}${sign}${PAD(Math.floor(abs / 60))}:${PAD(abs % 60)}`;
}
