import { describe, it, expect } from 'vitest';
import { normalizeVideoList, normalizeVideoTotal } from '../videoListUtils';

describe('videoListUtils', () => {
  it('normalizes flat arrays', () => {
    expect(normalizeVideoList([{ id: 1 }])).toEqual([{ id: 1 }]);
  });

  it('normalizes { data: [] } payloads', () => {
    expect(normalizeVideoList({ data: [], total: 0 })).toEqual([]);
  });

  it('normalizes nested legacy payloads', () => {
    expect(normalizeVideoList({ data: { data: [{ id: 2 }], total: 1 } })).toEqual([{ id: 2 }]);
  });

  it('returns empty array for unexpected shapes', () => {
    expect(normalizeVideoList({ data: { total: 0 } })).toEqual([]);
    expect(normalizeVideoList(null)).toEqual([]);
  });

  it('reads totals from flat and nested payloads', () => {
    expect(normalizeVideoTotal({ total: 5 }, [])).toBe(5);
    expect(normalizeVideoTotal({ data: { total: 3 } }, [])).toBe(3);
    expect(normalizeVideoTotal({}, [{ id: 1 }])).toBe(1);
  });
});
