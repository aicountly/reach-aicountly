import { describe, it, expect } from 'vitest';
import { ago } from '../format';

describe('ago', () => {
  const now = Date.parse('2026-10-22T12:00:00Z');

  it('reads the timestamps PostgreSQL TIMESTAMPTZ columns actually return', () => {
    // pg returns "+00" (hour-only offset); `new Date()` gives NaN for it.
    expect(ago('2026-10-22 10:00:00+00', now)).toBe('2h ago');
    expect(ago('2026-10-22 10:00:00.123456+00', now)).toBe('2h ago');
    expect(ago('2026-10-22 15:30:00+05:30', now)).toBe('2h ago');
    expect(ago('2026-10-22T10:00:00Z', now)).toBe('2h ago');
    expect(ago('2026-10-22 10:00:00', now)).toBe('2h ago'); // zone-less = UTC
  });

  it('falls back on garbage', () => {
    expect(ago('nope', now)).toBe('—');
    expect(ago(null, now)).toBe('—');
  });
});
