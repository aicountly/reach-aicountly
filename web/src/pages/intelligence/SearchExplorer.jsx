import { useEffect, useMemo, useRef, useState } from 'react';
import { ExternalLink, Search as SearchIcon } from 'lucide-react';
import { exploreSiteSearch } from '../../services/intelligenceService.js';
import { DrillPath } from '../../components/drill';
import {
  SEARCH_FILTER_KEYS, SEARCH_DIMENSIONS, FILTER_LABELS, nextDimension, formatSearchValue, num, pct, pos,
} from './searchDrill.js';

const PAGE_SIZE = 50;

const COLUMNS = [
  { key: 'clicks', label: 'Clicks', defaultDir: 'desc' },
  { key: 'impressions', label: 'Impressions', defaultDir: 'desc' },
  { key: 'ctr', label: 'CTR', defaultDir: 'desc' },
  { key: 'position', label: 'Avg position', defaultDir: 'asc' },
];

/**
 * Site-wide Search Console drill-down. Every row is a value of one dimension
 * within the filters chosen so far; clicking it adds that value as a filter
 * and regroups by the next dimension, so the viewer can go day → query →
 * page → device → country until a single Search Console row is left.
 * All state is in the URL (see useDrillFilters), so Back goes up one level.
 *
 * @param {{ drill: ReturnType<import('../../components/drill/useDrillFilters').useDrillFilters> }} props
 */
export default function SearchExplorer({ drill }) {
  const { filters, params } = drill;
  const requested = params.get('by') || '';
  const by = requested && SEARCH_DIMENSIONS[requested] && !filters[requested]
    ? requested
    : (nextDimension(filters) || 'date');
  const sort = params.get('sort') || (by === 'date' ? 'key' : 'clicks');
  const dir = params.get('dir') || '';
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [state, setState] = useState({ loading: true, error: null, rows: [], total: 0, totals: null, period: null, latest: null });
  const seq = useRef(0);

  const filterKey = SEARCH_FILTER_KEYS.map((k) => `${k}=${filters[k]}`).join('&');

  // A new level starts with an empty search box.
  useEffect(() => { setSearch(''); setDebounced(''); }, [filterKey, by]);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const query = useMemo(() => {
    const p = { group_by: by, limit: PAGE_SIZE, sort };
    if (dir) p.dir = dir;
    if (debounced) p.q = debounced;
    SEARCH_FILTER_KEYS.forEach((k) => { if (filters[k]) p[k] = filters[k]; });
    return p;
    // filterKey captures every filter value.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [by, sort, dir, debounced, filterKey]);

  const fetchRows = (offset) => {
    const id = ++seq.current;
    setState((s) => ({ ...s, loading: true, error: null }));
    exploreSiteSearch({ ...query, offset })
      .then((d) => {
        if (id !== seq.current) return;
        setState((s) => ({
          loading: false,
          error: null,
          rows: offset === 0 ? (d?.rows || []) : [...s.rows, ...(d?.rows || [])],
          total: d?.total_rows ?? 0,
          totals: d?.totals || null,
          period: d?.period || null,
          latest: d?.latest_date || null,
        }));
      })
      .catch((e) => {
        if (id !== seq.current) return;
        setState((s) => ({ ...s, loading: false, error: e.message || 'Could not load this breakdown.' }));
      });
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { fetchRows(0); }, [query]);

  const { loading, error, rows, total, totals, period } = state;
  const available = Object.keys(SEARCH_DIMENSIONS).filter((d) => !filters[d]);
  const leaf = available.length === 0;
  const maxClicks = Math.max(1, ...rows.map((r) => Number(r.clicks) || 0));

  const open = (row) => {
    if (row.dimension_value === '') return;
    const nextFilters = { ...filters, [by]: row.dimension_value };
    drill.drill({ [by]: row.dimension_value, by: nextDimension(nextFilters) || '', sort: '', dir: '' });
  };

  // Server defaults: metrics high→low, position low→high, days newest first,
  // other names A→Z. An explicit ?dir overrides.
  const effectiveDir = (key) => dir || (key === 'key'
    ? (by === 'date' ? 'desc' : 'asc')
    : (COLUMNS.find((c) => c.key === key)?.defaultDir || 'desc'));

  const sortBy = (key) => {
    const nextDir = sort === key ? (effectiveDir(key) === 'asc' ? 'desc' : 'asc') : '';
    drill.set({ sort: key, dir: nextDir });
  };

  const sortIndicator = (key) => {
    if (sort !== key) return '';
    return effectiveDir(key) === 'asc' ? ' ▲' : ' ▼';
  };

  return (
    <div data-testid="search-explorer">
      <DrillPath
        drill={drill}
        rootLabel="All search data"
        labels={FILTER_LABELS}
        format={formatSearchValue}
        onRoot={() => drill.upTo(-1, { by: '', sort: '', dir: '' })}
      />

      {period && (
        <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
          {period.from} → {period.to}
          {filters.date ? '' : ' · whole window'}
        </p>
      )}

      <div className="grid grid-3" style={{ marginBottom: '1rem' }}>
        {[
          { label: 'Clicks', value: num(totals?.clicks) },
          { label: 'Impressions', value: num(totals?.impressions) },
          { label: 'CTR', value: pct(totals?.ctr) },
          { label: 'Avg position', value: pos(totals?.avg_position) },
          { label: `${SEARCH_DIMENSIONS[by].plural} in this slice`, value: num(total) },
          { label: 'Search Console rows', value: num(totals?.rows) },
        ].map((s) => (
          <div key={s.label} className="stat-tile">
            <div className="stat-tile__label">{s.label}</div>
            <div className="stat-tile__value">{loading && !totals ? '…' : s.value}</div>
          </div>
        ))}
      </div>

      {(filters.page || filters.query) && (
        <div className="flex gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {filters.page && (
            <a className="btn btn-secondary btn-sm" href={filters.page} target="_blank" rel="noreferrer">
              <ExternalLink size={14} /> Open page
            </a>
          )}
          {filters.query && (
            <a
              className="btn btn-secondary btn-sm"
              href={`https://www.google.com/search?q=${encodeURIComponent(filters.query)}`}
              target="_blank"
              rel="noreferrer"
            >
              <SearchIcon size={14} /> Search Google for “{filters.query}”
            </a>
          )}
        </div>
      )}

      <p className="text-xs text-muted" style={{ margin: '0 0 0.75rem' }}>
        Built from query-level rows. Google withholds anonymised queries from these rows, so a slice can total a
        little less than the day totals on the overview.
      </p>

      {error && <div className="alert alert-danger">{error} <button type="button" className="link-btn" onClick={() => fetchRows(0)}>Retry</button></div>}

      {leaf ? (
        <div className="card" data-testid="search-leaf">
          <div className="card-body">
            <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.5rem' }}>Single Search Console row</h2>
            <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.5rem 1.25rem', margin: 0 }}>
              {Object.keys(SEARCH_DIMENSIONS).map((k) => (
                <div key={k}>
                  <dt className="text-xs text-secondary">{SEARCH_DIMENSIONS[k].label}</dt>
                  <dd style={{ margin: 0, wordBreak: 'break-all' }}>{formatSearchValue(k, filters[k])}</dd>
                </div>
              ))}
              <div><dt className="text-xs text-secondary">Clicks</dt><dd style={{ margin: 0 }}>{num(totals?.clicks)}</dd></div>
              <div><dt className="text-xs text-secondary">Impressions</dt><dd style={{ margin: 0 }}>{num(totals?.impressions)}</dd></div>
              <div><dt className="text-xs text-secondary">CTR</dt><dd style={{ margin: 0 }}>{pct(totals?.ctr)}</dd></div>
              <div><dt className="text-xs text-secondary">Position</dt><dd style={{ margin: 0 }}>{pos(totals?.avg_position)}</dd></div>
            </dl>
          </div>
        </div>
      ) : (
        <div className="card">
          <div className="card-body">
            <div className="flex items-center justify-between gap-2" style={{ flexWrap: 'wrap', marginBottom: '0.75rem' }}>
              <div className="drill-tabs" role="tablist" aria-label="Break down by" style={{ marginBottom: 0 }}>
                {available.map((d) => (
                  <button
                    key={d}
                    type="button"
                    role="tab"
                    aria-selected={by === d}
                    className={`btn btn-sm ${by === d ? 'btn-primary' : 'btn-secondary'}`}
                    onClick={() => drill.set({ by: d, sort: '', dir: '' })}
                  >
                    By {SEARCH_DIMENSIONS[d].label.toLowerCase()}
                  </button>
                ))}
              </div>
              {by !== 'date' && (
                <input
                  type="search"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={`Filter ${SEARCH_DIMENSIONS[by].plural.toLowerCase()}…`}
                  aria-label={`Filter ${SEARCH_DIMENSIONS[by].plural.toLowerCase()}`}
                  style={{ minWidth: 220 }}
                />
              )}
            </div>

            {!loading && rows.length === 0 ? (
              <p className="text-sm text-muted" style={{ margin: 0 }}>
                No {SEARCH_DIMENSIONS[by].plural.toLowerCase()} in this slice{debounced ? ` matching “${debounced}”` : ''}.
              </p>
            ) : (
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th className={`is-sortable${sort === 'key' ? ' is-sorted' : ''}`}>
                        <button type="button" onClick={() => sortBy('key')}>{SEARCH_DIMENSIONS[by].label}{sortIndicator('key')}</button>
                      </th>
                      {COLUMNS.map((c) => (
                        <th
                          key={c.key}
                          className={`is-sortable${sort === c.key ? ' is-sorted' : ''}`}
                          style={c.key === 'clicks' ? { width: '32%' } : { textAlign: 'right' }}
                          aria-sort={sort === c.key ? (effectiveDir(c.key) === 'asc' ? 'ascending' : 'descending') : undefined}
                        >
                          <button type="button" onClick={() => sortBy(c.key)}>{c.label}{sortIndicator(c.key)}</button>
                        </th>
                      ))}
                      {by !== 'date' && <th style={{ textAlign: 'right' }}>Days</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => {
                      const drillable = row.dimension_value !== '';
                      const label = formatSearchValue(by, row.dimension_value);
                      return (
                        <tr
                          key={row.dimension_value}
                          className={drillable ? 'is-drillable' : undefined}
                          onClick={drillable ? () => open(row) : undefined}
                        >
                          <td style={{ fontWeight: 500, wordBreak: 'break-all' }}>
                            {drillable ? (
                              <button
                                type="button"
                                className="drill-link"
                                onClick={(e) => { e.stopPropagation(); open(row); }}
                                title={`Drill into ${label}`}
                              >
                                {label}
                              </button>
                            ) : <span className="text-muted">{label}</span>}
                          </td>
                          <td>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                              <div
                                aria-hidden="true"
                                style={{
                                  height: '0.5rem',
                                  width: `${Math.max(2, (Number(row.clicks) / maxClicks) * 100)}%`,
                                  background: 'var(--color-info)',
                                  borderRadius: '2px',
                                }}
                              />
                              <span className="text-sm">{num(row.clicks)}</span>
                            </div>
                          </td>
                          <td style={{ textAlign: 'right' }}>{num(row.impressions)}</td>
                          <td style={{ textAlign: 'right' }}>{pct(row.ctr)}</td>
                          <td style={{ textAlign: 'right', fontWeight: 600 }}>{pos(row.avg_position)}</td>
                          {by !== 'date' && <td style={{ textAlign: 'right' }}>{num(row.days)}</td>}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            <div className="flex items-center justify-between" style={{ marginTop: '0.75rem', flexWrap: 'wrap', gap: '0.5rem' }}>
              <span className="text-xs text-muted">
                {loading ? 'Loading…' : `Showing ${rows.length.toLocaleString()} of ${num(total)} ${SEARCH_DIMENSIONS[by].plural.toLowerCase()}`}
              </span>
              {!loading && rows.length < total && (
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => fetchRows(rows.length)}>
                  Load {Math.min(PAGE_SIZE, total - rows.length)} more
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
