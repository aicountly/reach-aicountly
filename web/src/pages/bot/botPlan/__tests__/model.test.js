import { describe, it, expect } from 'vitest';
import { formatIstDate, formatIstDateTime } from '../model';

describe('Bot plan dates', () => {
  it('reads PostgreSQL timestamps with an hour-only offset', () => {
    expect(formatIstDateTime('2026-10-22 10:00:00+00')).toContain('22 Oct 2026');
    expect(formatIstDateTime('2026-10-22 10:00:00+00')).toContain('15:30');
    expect(formatIstDate('2026-10-22 20:00:00+00')).toBe('23 Oct 2026');
  });

  it('keeps an IST calendar date on its own day', () => {
    expect(formatIstDate('2026-10-18')).toBe('18 Oct 2026');
  });
});
