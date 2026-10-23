import { useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * A list filter kept in the URL instead of component state, so a drill link
 * (`/campaigns?status=running`) lands on the filtered list, and changing the
 * filter by hand keeps the URL shareable. Drop-in for useState:
 *
 *   const [status, setStatus] = useUrlFilter('status');
 *
 * Changes replace the history entry (typing into a filter must not flood
 * Back); an empty value (or the fallback) removes the param. Reset paging
 * in an effect on the value when the list pages.
 *
 * @param {string} key
 * @param {string} [fallback] value when the param is absent
 * @returns {[string, (value: string) => void]}
 */
export function useUrlFilter(key, fallback = '') {
  const [params, setParams] = useSearchParams();
  const value = params.get(key) ?? fallback;

  const setValue = useCallback((next) => {
    setParams((prev) => {
      const out = new URLSearchParams(prev);
      const v = typeof next === 'function' ? next(prev.get(key) ?? fallback) : next;
      if (v === undefined || v === null || v === '' || v === fallback) out.delete(key);
      else out.set(key, String(v));
      return out;
    }, { replace: true });
  }, [key, fallback, setParams]);

  return [value, setValue];
}
