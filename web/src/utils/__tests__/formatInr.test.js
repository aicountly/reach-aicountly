import { describe, it, expect } from 'vitest';
import { formatInr } from '../formatInr';
import { localDay } from '../localDay';

describe('formatInr', () => {
  it('groups digits the Indian way and keeps two decimals', () => {
    expect(formatInr('1234.50')).toBe('₹1,234.50');
    expect(formatInr('12345678.00')).toBe('₹1,23,45,678.00');
    expect(formatInr('999')).toBe('₹999.00');
    expect(formatInr('0.00')).toBe('₹0.00');
  });

  it('shows the fallback for missing or malformed values', () => {
    expect(formatInr(null)).toBe('—');
    expect(formatInr('')).toBe('—');
    expect(formatInr('abc')).toBe('—');
  });
});

describe('localDay', () => {
  it('formats the IST calendar date, whatever the browser zone', () => {
    expect(localDay(new Date(Date.UTC(2026, 9, 7, 17, 0)))).toBe('2026-10-07'); // 22:30 IST
    expect(localDay(new Date(Date.UTC(2026, 9, 7, 18, 40)))).toBe('2026-10-08'); // 00:10 IST
  });
});
