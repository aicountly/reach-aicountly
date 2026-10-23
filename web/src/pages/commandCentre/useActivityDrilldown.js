import { useCallback, useEffect, useRef, useState } from 'react';
import { dashboardService } from '../../services/dashboardService';

/** UI window id → the API's window name. */
const API_WINDOW = { h24: '24h', d7: '7d', d30: '30d' };

const EMPTY = { items: [], page: 0, total: null, groupsTotal: null, hasMore: false, grouped: false, counters: null, label: '' };

/**
 * The items behind one "What the bot did" counter, page by page, from
 * `GET v1/dashboard/command-centre/activity`. The server builds the list and
 * the counter from the same scope, so `total` always equals the tile.
 *
 * Reloads page 1 when the kind or window changes, and when `refreshKey`
 * changes (the section was rebuilt) unless the viewer has paged further —
 * a background refresh must not throw away what they loaded.
 *
 * @param {{ kind: string, win: string, refreshKey?: string|null, perPage?: number, enabled?: boolean }} opts
 */
export function useActivityDrilldown({ kind, win, refreshKey = null, perPage = 25, enabled = true }) {
  const [state, setState] = useState(EMPTY);
  const [loading, setLoading] = useState(enabled);
  const [error, setError] = useState(null);
  const seq = useRef(0);
  const pageRef = useRef(0);
  // "Load more" moves focus to the first newly loaded row (`focusIndex`;
  // attach `focusRef` to it), so a keyboard user carries on from there.
  const [focusIndex, setFocusIndex] = useState(null);
  const focusRef = useRef(null);

  const fetchPage = useCallback((page) => {
    const id = ++seq.current;
    setLoading(true);
    setError(null);
    // Promise.resolve().then: even a synchronous throw stays inside this card.
    return Promise.resolve()
      .then(() => dashboardService.commandCentreActivity({ kind: kind || 'all', window: API_WINDOW[win] || win, page, per_page: perPage }))
      .then((d) => {
        if (id !== seq.current) return;
        pageRef.current = page;
        setState((prev) => {
          const items = page === 1 ? (d.items || []) : [...prev.items, ...(d.items || [])];
          return {
            items,
            page,
            total: d.total ?? null,
            groupsTotal: d.groups_total ?? null,
            hasMore: Boolean(d.has_more),
            grouped: Boolean(d.grouped),
            counters: d.counters || null,
            label: d.label || '',
          };
        });
      })
      .catch((e) => { if (id === seq.current) setError(e.message || 'Could not load these items.'); })
      .finally(() => { if (id === seq.current) setLoading(false); });
  }, [kind, win, perPage]);

  useEffect(() => {
    if (!enabled) return;
    pageRef.current = 0;
    setState(EMPTY);
    setFocusIndex(null);
    fetchPage(1);
  }, [enabled, fetchPage]);

  const lastRefresh = useRef(refreshKey);
  useEffect(() => {
    if (!enabled || refreshKey === lastRefresh.current) return;
    lastRefresh.current = refreshKey;
    if (pageRef.current === 1) fetchPage(1);
  }, [enabled, refreshKey, fetchPage]);

  useEffect(() => {
    if (focusIndex !== null && state.items.length > focusIndex && focusRef.current) {
      focusRef.current.focus();
      setFocusIndex(null);
    }
  }, [focusIndex, state.items.length]);

  const fetchNext = useCallback(() => fetchPage(pageRef.current + 1), [fetchPage]);
  const loaded = state.items.length;
  const loadMore = useCallback(() => { setFocusIndex(loaded); return fetchNext(); }, [fetchNext, loaded]);

  // A failed first page or "load more" retries the page that failed.
  return { ...state, loading, error, loadMore, retry: fetchNext, focusIndex, focusRef };
}
