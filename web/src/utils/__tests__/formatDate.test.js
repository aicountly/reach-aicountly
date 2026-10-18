import { describe, it, expect, afterEach } from 'vitest';
import {
  DEFAULT_TIME_ZONE,
  displayMonth,
  formatDate,
  formatDateTime,
  formatMonthYear,
  formatRelative,
  formatTime,
  fromDateTimeInputValue,
  getDisplayTimeZone,
  isPast,
  setDisplayTimeZone,
  timeZoneLabel,
  toDate,
  toDateInputValue,
  toDateTimeInputValue,
  todayInputValue,
} from '../formatDate';
import { localDay } from '../localDay';

afterEach(() => setDisplayTimeZone(DEFAULT_TIME_ZONE));

describe('display zone', () => {
  it('defaults to IST and labels it', () => {
    expect(getDisplayTimeZone()).toBe('Asia/Kolkata');
    expect(timeZoneLabel()).toBe('IST');
  });

  it('follows ui.timezone, and falls back to IST — never the browser zone — on a bad value', () => {
    expect(setDisplayTimeZone('UTC')).toBe('UTC');
    expect(formatDateTime('2026-10-18T01:04:00Z')).toBe('18-10-2026 01:04 UTC');
    expect(setDisplayTimeZone('Not/AZone')).toBe('Asia/Kolkata');
    expect(setDisplayTimeZone(undefined)).toBe('Asia/Kolkata');
    expect(formatDateTime('2026-10-18T01:04:00Z')).toBe('18-10-2026 06:34 IST');
  });
});

describe('formatDateTime (IST)', () => {
  it('reads a zone-less API string as UTC — the Job Monitor case', () => {
    // Stored UTC 01:04 is 06:34 in India; before this it rendered as "01:04".
    expect(formatDateTime('2026-10-18 01:04:00')).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime('2026-10-18T01:04')).toBe('18-10-2026 06:34 IST');
  });

  it('honours Z and explicit offsets, including Postgres "+00"', () => {
    expect(formatDateTime('2026-10-18T01:04:00Z')).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime('2026-10-18T01:04:00.000Z')).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime('2026-10-18 01:04:00+00')).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime('2026-10-18 01:04:00.123456+00')).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime('2026-10-18 06:34:00+05:30')).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime('2026-10-17T21:04:00-04:00')).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime('2026-10-18T01:04:00+0000')).toBe('18-10-2026 06:34 IST');
  });

  it('crosses midnight into the IST day', () => {
    expect(formatDateTime('2026-10-18 20:00:00')).toBe('19-10-2026 01:30 IST');
    expect(formatDate('2026-10-18 20:00:00')).toBe('19-10-2026');
  });

  it('renders 24-hour time, midnight as 00:00', () => {
    expect(formatDateTime('2026-08-02T18:30:00Z')).toBe('03-08-2026 00:00 IST');
    expect(formatDateTime('2026-08-03T09:00:00Z')).toBe('03-08-2026 14:30 IST');
  });

  it('is DST-free: the same +05:30 in January and July', () => {
    expect(formatDateTime('2026-01-15 12:00:00')).toBe('15-01-2026 17:30 IST');
    expect(formatDateTime('2026-07-15 12:00:00')).toBe('15-07-2026 17:30 IST');
  });

  it('drops the suffix on request and falls back on empty input', () => {
    expect(formatDateTime('2026-10-18 01:04:00', '—', { zone: false })).toBe('18-10-2026 06:34');
    expect(formatDateTime(null)).toBe('—');
    expect(formatDateTime(null, '')).toBe('');
    expect(formatDateTime('not a date')).toBe('—');
  });

  it('renders a date-only value as its date, with no invented time', () => {
    expect(formatDateTime('2026-08-03')).toBe('03-08-2026');
  });

  it('accepts Date objects and epoch milliseconds as instants', () => {
    expect(formatDateTime(new Date(Date.UTC(2026, 9, 18, 1, 4)))).toBe('18-10-2026 06:34 IST');
    expect(formatDateTime(Date.UTC(2026, 9, 18, 1, 4))).toBe('18-10-2026 06:34 IST');
  });
});

describe('formatDate', () => {
  it('renders dd-mm-yyyy, zero-padded', () => {
    expect(formatDate('2026-08-03')).toBe('03-08-2026');
    expect(formatDate('2026-12-25')).toBe('25-12-2026');
  });

  it('never shifts a date-only value (publish dates, report dates)', () => {
    expect(formatDate('2026-08-03')).toBe('03-08-2026');
    expect(formatDate('2026-01-01')).toBe('01-01-2026');
    setDisplayTimeZone('America/New_York');
    expect(formatDate('2026-08-03')).toBe('03-08-2026');
    expect(toDateInputValue('2026-08-03')).toBe('2026-08-03');
  });

  it('maps a full-width year literally, not through the 1900s window', () => {
    expect(formatDate('0026-08-03')).toBe('03-08-0026');
    expect(formatDate('0099-01-01')).toBe('01-01-0099');
  });

  it('falls back rather than printing "Invalid Date"', () => {
    expect(formatDate(null)).toBe('—');
    expect(formatDate(undefined)).toBe('—');
    expect(formatDate('')).toBe('—');
    expect(formatDate('not a date')).toBe('—');
    expect(formatDate(null, 'n/a')).toBe('n/a');
  });
});

describe('formatTime', () => {
  it('renders HH:mm IST', () => {
    expect(formatTime('2026-08-03 09:00:00')).toBe('14:30 IST');
    expect(formatTime('2026-08-03 09:00:00', '—', { zone: false })).toBe('14:30');
    expect(formatTime(null)).toBe('—');
    expect(formatTime('2026-08-03')).toBe('—');
  });
});

describe('formatRelative', () => {
  const now = Date.UTC(2026, 9, 18, 12, 0, 0);

  it('counts between instants, so a zone-less UTC string is not 5h30 off', () => {
    expect(formatRelative('2026-10-18 00:00:00', { now })).toBe('12h ago');
    expect(formatRelative('2026-10-18T05:30:00+05:30', { now })).toBe('12h ago');
  });

  it('uses just now / m / h / d, the future, and a date past 30 days', () => {
    expect(formatRelative('2026-10-18 11:59:40', { now })).toBe('just now');
    expect(formatRelative('2026-10-18 11:55:00', { now })).toBe('5m ago');
    expect(formatRelative('2026-10-15 12:00:00', { now })).toBe('3d ago');
    expect(formatRelative('2026-10-18 14:00:00', { now })).toBe('in 2h');
    expect(formatRelative('2026-08-01 12:00:00', { now })).toBe('01-08-2026');
    expect(formatRelative(null, { now })).toBe('—');
  });
});

describe('isPast / toDate', () => {
  it('compares instants and treats missing values as not past', () => {
    const now = Date.UTC(2026, 9, 18, 1, 0);
    expect(isPast('2026-10-18 00:59:00', now)).toBe(true);
    expect(isPast('2026-10-18 01:01:00', now)).toBe(false);
    expect(isPast(null, now)).toBe(false);
    expect(isPast('junk', now)).toBe(false);
    expect(toDate('2026-10-18 01:04:00').toISOString()).toBe('2026-10-18T01:04:00.000Z');
  });
});

describe('wire formats', () => {
  it('toDateInputValue stays ISO yyyy-mm-dd and uses the IST day of an instant', () => {
    expect(toDateInputValue('2026-08-03')).toBe('2026-08-03');
    expect(toDateInputValue('2026-08-03 14:30:00')).toBe('2026-08-03');
    expect(toDateInputValue('2026-08-03 20:00:00')).toBe('2026-08-04');
    expect(localDay(new Date(Date.UTC(2026, 7, 3, 20, 0)))).toBe('2026-08-04');
  });

  it('returns empty string so a controlled input stays controlled', () => {
    expect(toDateInputValue(null)).toBe('');
    expect(toDateInputValue('nonsense')).toBe('');
    expect(toDateTimeInputValue(null)).toBe('');
  });

  it('todayInputValue is wire format, never display format', () => {
    expect(todayInputValue()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('round-trips a datetime-local value as IST, sent with its offset', () => {
    // The operator types 10:00 meaning 10:00 IST.
    expect(fromDateTimeInputValue('2026-10-18T10:00')).toBe('2026-10-18T10:00:00+05:30');
    expect(toDate(fromDateTimeInputValue('2026-10-18T10:00')).toISOString()).toBe('2026-10-18T04:30:00.000Z');
    expect(toDateTimeInputValue('2026-10-18T04:30:00Z')).toBe('2026-10-18T10:00');
    expect(toDateTimeInputValue('2026-10-18 04:30:00+00')).toBe('2026-10-18T10:00');
    expect(fromDateTimeInputValue('')).toBeNull();
    expect(fromDateTimeInputValue('junk')).toBeNull();
  });

  it('applies a DST zone offset for the typed date when ui.timezone is not IST', () => {
    setDisplayTimeZone('Europe/London');
    expect(fromDateTimeInputValue('2026-07-01T10:00')).toBe('2026-07-01T10:00:00+01:00');
    expect(fromDateTimeInputValue('2026-01-01T10:00')).toBe('2026-01-01T10:00:00+00:00');
  });
});

describe('calendar month', () => {
  it('is the IST month, which turns before the UTC one', () => {
    // 31 Oct 20:00 UTC is already 1 Nov 01:30 in India.
    expect(displayMonth(0, Date.UTC(2026, 9, 31, 20, 0))).toEqual({ year: 2026, month: 10 });
    expect(displayMonth(-11, Date.UTC(2026, 9, 31, 20, 0))).toEqual({ year: 2025, month: 11 });
    expect(displayMonth(2, Date.UTC(2026, 9, 31, 20, 0))).toEqual({ year: 2027, month: 0 });
    expect(formatMonthYear(2026, 9)).toBe('October 2026');
  });
});
