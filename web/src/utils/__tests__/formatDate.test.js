import { describe, it, expect } from 'vitest';
import {
  formatDate,
  formatDateTime,
  formatTime,
  toDateInputValue,
  todayInputValue,
} from '../formatDate';

describe('formatDate', () => {
  it('renders dd-mm-yyyy, zero-padded', () => {
    expect(formatDate('2026-08-03')).toBe('03-08-2026');
    expect(formatDate('2026-12-25')).toBe('25-12-2026');
  });

  it('does not shift a date-only value across a timezone boundary', () => {
    // `new Date('2026-08-03')` is UTC midnight, which is still 02 Aug for
    // anyone west of Greenwich. The bare-date path must sidestep that.
    expect(formatDate('2026-08-03')).toBe('03-08-2026');
    expect(formatDate('2026-01-01')).toBe('01-01-2026');
  });

  it('accepts the datetime shapes the API emits', () => {
    expect(formatDate('2026-08-03T14:30:00Z')).toMatch(/^0[34]-08-2026$/);
    expect(formatDate('2026-08-03 14:30:00')).toBe('03-08-2026');
    expect(formatDate(new Date(2026, 7, 3))).toBe('03-08-2026');
  });

  // Postgres writes TIMESTAMPTZ with a space separator and an hour-only
  // offset. Neither is valid ISO 8601, and naive normalisation to
  // "2026-08-03T10:00:00+00" yields Invalid Date — which would have blanked
  // every Created/Updated/Published column in the app.
  it('parses the exact wire format Postgres emits for TIMESTAMPTZ', () => {
    expect(formatDate('2026-08-03 10:00:00+00')).not.toBe('—');
    expect(formatDate('2026-08-03 10:00:00.123456+00')).not.toBe('—');
    expect(formatDate('2026-08-03 10:00:00+05:30')).not.toBe('—');
    expect(formatDate('2026-08-03T10:00:00Z')).not.toBe('—');
  });

  it('maps a full-width year literally, not through the 1900s window', () => {
    // `new Date(26, 7, 3)` is 1926 — the Date constructor's two-digit-year
    // legacy rule. A four-digit "0026" must stay year 26.
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

describe('formatDateTime', () => {
  it('appends 24-hour local time to the house date format', () => {
    expect(formatDateTime('2026-08-03 14:30:00')).toBe('03-08-2026 14:30');
    expect(formatDateTime('2026-08-03 09:05:00')).toBe('03-08-2026 09:05');
  });

  it('renders midnight as 00:00, not 12:00', () => {
    expect(formatDateTime('2026-08-03 00:00:00')).toBe('03-08-2026 00:00');
  });

  it('falls back on empty input', () => {
    expect(formatDateTime(null)).toBe('—');
    expect(formatDateTime(null, '')).toBe('');
  });
});

describe('formatTime', () => {
  it('renders HH:mm', () => {
    expect(formatTime('2026-08-03 14:30:00')).toBe('14:30');
    expect(formatTime(null)).toBe('—');
  });
});

describe('toDateInputValue', () => {
  it('stays ISO yyyy-mm-dd — <input type="date"> and API params require it', () => {
    expect(toDateInputValue('2026-08-03')).toBe('2026-08-03');
    expect(toDateInputValue('2026-08-03 14:30:00')).toBe('2026-08-03');
    expect(toDateInputValue(new Date(2026, 7, 3))).toBe('2026-08-03');
  });

  it('returns empty string so a controlled input stays controlled', () => {
    expect(toDateInputValue(null)).toBe('');
    expect(toDateInputValue('nonsense')).toBe('');
  });

  it('todayInputValue is wire format, never display format', () => {
    expect(todayInputValue()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });
});
