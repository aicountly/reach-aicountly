/**
 * THE single source of truth for how dates are rendered in the UI.
 *
 * House format is dd-mm-yyyy. Before this module every screen called
 * `new Date(x).toLocaleDateString()` directly, so the format followed
 * whatever locale the operator's browser happened to advertise (en-US gave
 * 8/3/2026 for the 3rd of August) — and a handful of screens rendered the
 * raw API string, showing 2026-08-03. Both are fixed by routing every
 * display through here.
 *
 * Machine-facing values are deliberately NOT this format: `<input
 * type="date">` and API query params require ISO yyyy-mm-dd by spec, so
 * they use `toDateInputValue()` instead. Never swap one for the other.
 */

const PAD = (n) => String(n).padStart(2, '0');

/** Date-only API values ("2026-08-03") carry no timezone. */
const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** A trailing hour-only UTC offset, as Postgres writes it: "+00", "-05". */
const SHORT_OFFSET = /([+-]\d{2})$/;

const asDate = (d) => (Number.isNaN(d.getTime()) ? null : d);

/**
 * Postgres serialises TIMESTAMPTZ as "2026-08-03 10:00:00+00" — a space
 * separator and an hour-only offset, neither of which is valid ISO 8601.
 * Turn it into something every engine parses identically.
 */
function normalize(raw) {
  // "2026-08-03 10:30:00" → "...T10:30:00". Safari rejects the space form.
  let out = raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw;
  // "+00" → "+00:00". ISO 8601 requires minutes; without them V8 returns
  // Invalid Date, which would blank every timestamp column in the app.
  out = out.replace(SHORT_OFFSET, '$1:00');
  return out;
}

/**
 * Parse the shapes the API actually emits into a local-time Date.
 * Returns null for anything unusable so callers can render a fallback
 * rather than the string "Invalid Date".
 */
function parse(value) {
  if (value === null || value === undefined || value === '') return null;
  if (value instanceof Date) return asDate(value);
  if (typeof value === 'number') return asDate(new Date(value));

  const raw = String(value).trim();
  if (!raw) return null;

  // A bare date must not be shifted by the viewer's timezone: `new
  // Date('2026-08-03')` is UTC midnight, which renders as the 2nd for
  // anyone west of Greenwich. Build it in local time instead — and set the
  // year explicitly, because the Date(y, m, d) constructor maps years
  // 0-99 onto 1900-1999 ("0026" would silently become 1926).
  const dateOnly = raw.match(DATE_ONLY);
  if (dateOnly) {
    const [, y, m, d] = dateOnly;
    const built = new Date(Number(y), Number(m) - 1, Number(d));
    built.setFullYear(Number(y));
    return asDate(built);
  }

  // Fall back to the raw string when normalisation does not yield a valid
  // date: V8's lenient legacy parser handles some shapes that strict ISO
  // does not, so this can never parse worse than a bare `new Date(raw)`.
  return asDate(new Date(normalize(raw))) ?? asDate(new Date(raw));
}

/** dd-mm-yyyy — the house date format. Year is padded so yyyy always holds. */
export function formatDate(value, fallback = '—') {
  const d = parse(value);
  if (!d) return fallback;
  const year = String(d.getFullYear()).padStart(4, '0');
  return `${PAD(d.getDate())}-${PAD(d.getMonth() + 1)}-${year}`;
}

/** dd-mm-yyyy HH:mm, 24-hour, in the viewer's timezone. */
export function formatDateTime(value, fallback = '—') {
  const d = parse(value);
  if (!d) return fallback;
  return `${formatDate(d)} ${PAD(d.getHours())}:${PAD(d.getMinutes())}`;
}

/** HH:mm, 24-hour, in the viewer's timezone. */
export function formatTime(value, fallback = '—') {
  const d = parse(value);
  if (!d) return fallback;
  return `${PAD(d.getHours())}:${PAD(d.getMinutes())}`;
}

/**
 * yyyy-mm-dd for `<input type="date">` and API query params — the wire
 * format, never a display format. Returns '' so a controlled input stays
 * controlled when the value is missing.
 */
export function toDateInputValue(value, fallback = '') {
  const d = parse(value);
  if (!d) return fallback;
  return `${d.getFullYear()}-${PAD(d.getMonth() + 1)}-${PAD(d.getDate())}`;
}

/** Today in wire format — for input defaults and query params. */
export function todayInputValue() {
  return toDateInputValue(new Date());
}
