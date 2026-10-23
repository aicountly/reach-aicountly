import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Drill-down state kept in the URL, so every step is shareable and the
 * browser's Back button walks back up the path.
 *
 * `keys` are the filter params this view understands. `steps` lists the
 * active ones in the order they were chosen (URLSearchParams keeps insertion
 * order and `drill` re-appends a key it changes), which is what DrillPath
 * renders as the trail.
 *
 * - drill(patch)   — push a narrower step (adds/changes filters; any other
 *                    params in `patch`, e.g. a tab, are set alongside).
 * - set(key, v)    — change params in place (no history entry), for inputs
 *                    the user types into; set({ k: v, … }) changes several.
 * - upTo(i)        — go back to step i (drops later steps); -1 is the root.
 * - clear(key)     — drop one step.
 *
 * @param {string[]} keys
 */
export function useDrillFilters(keys) {
  const [params, setParams] = useSearchParams();
  const keyList = keys.join('|');

  const filters = useMemo(() => {
    const out = {};
    keyList.split('|').forEach((k) => { out[k] = params.get(k) ?? ''; });
    return out;
  }, [params, keyList]);

  const steps = useMemo(() => {
    const known = keyList.split('|');
    const seen = new Set();
    const out = [];
    params.forEach((value, key) => {
      if (known.includes(key) && value !== '' && !seen.has(key)) {
        seen.add(key);
        out.push({ key, value });
      }
    });
    return out;
  }, [params, keyList]);

  const apply = useCallback((mutate, replace) => {
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      mutate(next);
      return next;
    }, { replace });
  }, [setParams]);

  const drill = useCallback((patch) => apply((next) => {
    Object.entries(patch).forEach(([key, value]) => {
      next.delete(key);
      if (value !== undefined && value !== null && value !== '') next.set(key, String(value));
    });
  }, false), [apply]);

  const set = useCallback((key, value) => apply((next) => {
    const patch = typeof key === 'object' ? key : { [key]: value };
    Object.entries(patch).forEach(([k, v]) => {
      if (v === undefined || v === null || v === '') next.delete(k);
      else next.set(k, String(v));
    });
  }, true), [apply]);

  const clear = useCallback((key) => apply((next) => { next.delete(key); }, false), [apply]);

  const upTo = useCallback((index, extra = {}) => apply((next) => {
    steps.slice(index + 1).forEach(({ key }) => next.delete(key));
    Object.entries(extra).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') next.delete(key);
      else next.set(key, String(value));
    });
  }, false), [apply, steps]);

  return { filters, steps, depth: steps.length, drill, set, clear, upTo, params };
}
