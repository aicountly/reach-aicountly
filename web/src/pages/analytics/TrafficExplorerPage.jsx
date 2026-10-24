import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, ExternalLink } from 'lucide-react';
import { analyticsService } from '../../services/analyticsService';
import { DrillPath, drillHref, useDrillFilters } from '../../components/drill';
import { ROUTES } from '../../constants/routes';
import {
  DAYS_OPTIONS, LEAD_SUBMIT_EVENTS, STREAM_OPTIONS, TRAFFIC_DIMENSIONS, TRAFFIC_FILTER_KEYS, TRAFFIC_FILTER_LABELS,
  formatTrafficValue, isoDay, nextTrafficDimension, num, pct, streamLabel,
} from '../../components/analytics/trafficDrill';

const PAGE_SIZE = 50;

const COLUMNS = [
  { key: 'sessions', label: 'Sessions' },
  { key: 'users', label: 'Users' },
  { key: 'pageviews', label: 'Pageviews' },
  { key: 'bounce_rate', label: 'Bounce rate' },
  { key: 'events', label: 'Events' },
];

const defaultSort = (by) => (by === 'date' ? 'key' : by === 'event' ? 'events' : 'sessions');

/**
 * GA4 traffic drill-down. Every row is one value of a dimension within the
 * filters chosen so far; clicking it adds that value and regroups by the next
 * dimension, so the viewer can go day → channel → source → page → device →
 * country → event until a single GA4 report row is left. Everything is in the
 * URL (useDrillFilters), so Back walks up one level and a step can be shared.
 * Reads GA4 live (cached an hour server side); an unconfigured stream shows
 * why, never numbers.
 */
export function TrafficExplorerPage() {
  const drill = useDrillFilters(TRAFFIC_FILTER_KEYS);
  const { filters, params } = drill;
  const days = DAYS_OPTIONS.includes(Number(params.get('days'))) ? Number(params.get('days')) : 30;
  const stream = params.get('stream') || 'all';
  const requested = params.get('by') || '';
  const by = requested && TRAFFIC_DIMENSIONS[requested] && !filters[requested]
    ? requested
    : (nextTrafficDimension(filters) || 'date');
  const sort = params.get('sort') || defaultSort(by);
  const dir = params.get('dir') || '';
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [state, setState] = useState({ loading: true, error: null, data: null, rows: [] });
  const seq = useRef(0);

  const filterKey = TRAFFIC_FILTER_KEYS.map((k) => `${k}=${filters[k]}`).join('&');

  // A new level starts with an empty search box.
  useEffect(() => { setSearch(''); setDebounced(''); }, [filterKey, by]);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const query = useMemo(() => {
    const p = { group_by: by, days, stream, limit: PAGE_SIZE, sort };
    if (dir) p.dir = dir;
    if (debounced) p.q = debounced;
    TRAFFIC_FILTER_KEYS.forEach((k) => { if (filters[k]) p[k] = filters[k]; });
    return p;
    // filterKey captures every filter value.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [by, days, stream, sort, dir, debounced, filterKey]);

  const fetchRows = (offset) => {
    const id = ++seq.current;
    setState((s) => ({ ...s, loading: true, error: null }));
    analyticsService.trafficExplore({ ...query, offset })
      .then((d) => {
        if (id !== seq.current) return;
        setState((s) => ({
          loading: false,
          error: null,
          data: d,
          rows: offset === 0 ? (d?.rows || []) : [...s.rows, ...(d?.rows || [])],
        }));
      })
      .catch((e) => {
        if (id !== seq.current) return;
        setState((s) => ({ ...s, loading: false, error: e.message || 'Could not load this breakdown.' }));
      });
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { fetchRows(0); }, [query]);

  const { loading, error, data, rows } = state;
  const unconfigured = Boolean(data?._unconfigured);
  const totals = data?.totals;
  const total = data?.total_rows ?? 0;
  const available = Object.keys(TRAFFIC_DIMENSIONS).filter((d) => !filters[d]);
  const leaf = available.length === 0;
  const maxSessions = Math.max(1, ...rows.map((r) => Number(r.sessions) || 0));
  const sites = data?.sites || [];

  const open = (row) => {
    const nextFilters = { ...filters, [by]: row.dimension_value };
    drill.drill({ [by]: row.dimension_value, by: nextTrafficDimension(nextFilters) || '', sort: '', dir: '' });
  };

  // Server defaults: metrics high → low, days newest first, other names A → Z.
  const effectiveDir = (key) => dir || (key === 'key' && by !== 'date' ? 'asc' : 'desc');
  const sortBy = (key) => {
    const nextDir = sort === key ? (effectiveDir(key) === 'asc' ? 'desc' : 'asc') : '';
    drill.set({ sort: key, dir: nextDir });
  };
  const sortIndicator = (key) => (sort !== key ? '' : (effectiveDir(key) === 'asc' ? ' ▲' : ' ▼'));

  // Lead submits link to the leads Reach stored for the same day or window.
  const leadsHref = filters.event && LEAD_SUBMIT_EVENTS.has(filters.event)
    ? (filters.date
      ? drillHref(ROUTES.LEADS, { created_from: isoDay(filters.date), created_to: isoDay(filters.date) })
      : drillHref(ROUTES.LEADS, { days }))
    : null;

  return (
    <div data-testid="traffic-explorer">
      <div className="page-header flex items-center justify-between" style={{ flexWrap: 'wrap', gap: '0.75rem' }}>
        <div>
          <h1>Traffic explorer</h1>
          <p className="text-sm text-muted">
            <Link to={ROUTES.ANALYTICS}>Analytics</Link> › GA4 traffic for <strong>{streamLabel(stream)}</strong>, last {days} days
          </p>
        </div>
        <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
          <select
            className="input"
            aria-label="Site"
            value={stream}
            onChange={(e) => drill.set({ stream: e.target.value === 'all' ? '' : e.target.value })}
            style={{ width: 'auto', maxWidth: '100%' }}
          >
            {!STREAM_OPTIONS.some((o) => o.value === stream) && <option value={stream}>{streamLabel(stream)}</option>}
            {STREAM_OPTIONS.map((opt) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
          </select>
          <select
            className="input"
            aria-label="Window"
            value={days}
            onChange={(e) => drill.set({ days: Number(e.target.value) === 30 ? '' : e.target.value })}
            style={{ width: 'auto' }}
          >
            {DAYS_OPTIONS.map((d) => <option key={d} value={d}>Last {d} days</option>)}
          </select>
        </div>
      </div>

      <DrillPath
        drill={drill}
        rootLabel="All traffic"
        labels={TRAFFIC_FILTER_LABELS}
        format={formatTrafficValue}
        onRoot={() => drill.upTo(-1, { by: '', sort: '', dir: '' })}
      />

      {data?.period && (
        <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
          {data.period.from} → {data.period.to}{filters.date ? '' : ' · whole window'}
        </p>
      )}

      {error && (
        <div className="alert alert-danger">
          {error} <button type="button" className="link-btn" onClick={() => fetchRows(0)}>Retry</button>
        </div>
      )}

      {unconfigured ? (
        <div className="alert alert-warning flex items-start gap-2" data-testid="traffic-unconfigured">
          <AlertCircle size={16} style={{ flexShrink: 0, marginTop: 2 }} />
          <span>
            <strong>{streamLabel(stream)}:</strong>{' '}
            {data._reason || 'GA4 is not configured for this stream.'} No traffic figures are shown until GA4 answers.
          </span>
        </div>
      ) : (
        <>
          <div className="grid grid-3" style={{ marginBottom: '1rem' }}>
            {[
              { label: 'Sessions', value: num(totals?.sessions) },
              { label: 'Users', value: num(totals?.users) },
              { label: 'Pageviews', value: num(totals?.pageviews) },
              { label: 'Bounce rate', value: pct(totals?.bounce_rate) },
              { label: 'Events', value: num(totals?.events) },
              {
                label: leaf ? 'GA4 report rows' : `${TRAFFIC_DIMENSIONS[by].plural} in this slice`,
                value: `${num(total)}${data?.total_rows_approx ? '+' : ''}`,
              },
            ].map((s) => (
              <div key={s.label} className="stat-tile">
                <div className="stat-tile__label">{s.label}</div>
                <div className="stat-tile__value">{loading && !totals ? '…' : s.value}</div>
              </div>
            ))}
          </div>

          <LeafActions filters={filters} sites={sites} identityId={data?.page_content_identity_id} leadsHref={leadsHref} days={days} />

          <p className="text-xs text-muted" style={{ margin: '0 0 0.75rem' }}>
            Live from the GA4 Data API (cached for an hour). Users are counted per row, so they do not add up across
            rows; bounce rate is GA4&apos;s own figure for each slice.
          </p>

          {leaf ? (
            <LeafRow filters={filters} totals={totals} />
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
                        By {TRAFFIC_DIMENSIONS[d].label.toLowerCase()}
                      </button>
                    ))}
                  </div>
                  {by !== 'date' && (
                    <input
                      type="search"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      placeholder={`Filter ${TRAFFIC_DIMENSIONS[by].plural.toLowerCase()}…`}
                      aria-label={`Filter ${TRAFFIC_DIMENSIONS[by].plural.toLowerCase()}`}
                      style={{ minWidth: 220 }}
                    />
                  )}
                </div>

                {!loading && rows.length === 0 ? (
                  <p className="text-sm text-muted" style={{ margin: 0 }}>
                    No {TRAFFIC_DIMENSIONS[by].plural.toLowerCase()} in this slice{debounced ? ` matching “${debounced}”` : ''}.
                  </p>
                ) : (
                  <div className="table-wrap">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th className={`is-sortable${sort === 'key' ? ' is-sorted' : ''}`}>
                            <button type="button" onClick={() => sortBy('key')}>{TRAFFIC_DIMENSIONS[by].label}{sortIndicator('key')}</button>
                          </th>
                          {COLUMNS.map((c) => (
                            <th
                              key={c.key}
                              className={`is-sortable${sort === c.key ? ' is-sorted' : ''}`}
                              style={c.key === 'sessions' ? { width: '28%' } : { textAlign: 'right' }}
                              aria-sort={sort === c.key ? (effectiveDir(c.key) === 'asc' ? 'ascending' : 'descending') : undefined}
                            >
                              <button type="button" onClick={() => sortBy(c.key)}>{c.label}{sortIndicator(c.key)}</button>
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {rows.map((row) => {
                          const label = formatTrafficValue(by, row.dimension_value);
                          return (
                            <tr key={row.dimension_value} className="is-drillable" onClick={() => open(row)}>
                              <td style={{ fontWeight: 500, wordBreak: 'break-all' }}>
                                <button
                                  type="button"
                                  className="drill-link"
                                  onClick={(e) => { e.stopPropagation(); open(row); }}
                                  title={`Drill into ${label}`}
                                >
                                  {label}
                                </button>
                                {row.content_identity_id && (
                                  <Link
                                    to={`/intelligence/content/${row.content_identity_id}`}
                                    className="text-xs"
                                    style={{ marginLeft: '0.5rem' }}
                                    onClick={(e) => e.stopPropagation()}
                                  >
                                    Content analytics ›
                                  </Link>
                                )}
                              </td>
                              <td>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                                  <div
                                    aria-hidden="true"
                                    style={{
                                      height: '0.5rem',
                                      width: `${Math.max(2, (Number(row.sessions) / maxSessions) * 100)}%`,
                                      background: 'var(--color-info)',
                                      borderRadius: '2px',
                                    }}
                                  />
                                  <span className="text-sm">{num(row.sessions)}</span>
                                </div>
                              </td>
                              <td style={{ textAlign: 'right' }}>{num(row.users)}</td>
                              <td style={{ textAlign: 'right' }}>{num(row.pageviews)}</td>
                              <td style={{ textAlign: 'right' }}>{pct(row.bounce_rate)}</td>
                              <td style={{ textAlign: 'right' }}>{num(row.events)}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}

                <div className="flex items-center justify-between" style={{ marginTop: '0.75rem', flexWrap: 'wrap', gap: '0.5rem' }}>
                  <span className="text-xs text-muted">
                    {loading
                      ? 'Loading…'
                      : `Showing ${rows.length.toLocaleString()} of ${num(total)}${data?.total_rows_approx ? '+' : ''} ${TRAFFIC_DIMENSIONS[by].plural.toLowerCase()}`}
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
        </>
      )}
    </div>
  );
}

/** Where a narrowed slice leads outside GA4: the page itself, its Reach content, the leads it produced. */
function LeafActions({ filters, sites, identityId, leadsHref, days }) {
  if (!filters.page && !leadsHref) return null;
  return (
    <div className="flex gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
      {filters.page && sites.map((site) => (
        <a
          key={site}
          className="btn btn-secondary btn-sm"
          href={`${String(site).replace(/\/$/, '')}${filters.page}`}
          target="_blank"
          rel="noreferrer"
        >
          <ExternalLink size={14} /> Open page{sites.length > 1 ? ` on ${String(site).replace(/^https?:\/\//, '')}` : ''}
        </a>
      ))}
      {filters.page && identityId && (
        <Link className="btn btn-secondary btn-sm" to={`/intelligence/content/${identityId}`}>
          Reach content analytics ›
        </Link>
      )}
      {leadsHref && (
        <Link className="btn btn-secondary btn-sm" to={leadsHref}>
          Leads Reach captured {filters.date ? `on ${isoDay(filters.date)}` : `in the last ${days} days`} ›
        </Link>
      )}
    </div>
  );
}

function LeafRow({ filters, totals }) {
  return (
    <div className="card" data-testid="traffic-leaf">
      <div className="card-body">
        <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.5rem' }}>Single GA4 report row</h2>
        <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.5rem 1.25rem', margin: 0 }}>
          {Object.keys(TRAFFIC_DIMENSIONS).map((k) => (
            <div key={k}>
              <dt className="text-xs text-secondary">{TRAFFIC_DIMENSIONS[k].label}</dt>
              <dd style={{ margin: 0, wordBreak: 'break-all' }}>{formatTrafficValue(k, filters[k])}</dd>
            </div>
          ))}
          {COLUMNS.map((c) => (
            <div key={c.key}>
              <dt className="text-xs text-secondary">{c.label}</dt>
              <dd style={{ margin: 0 }}>{c.key === 'bounce_rate' ? pct(totals?.[c.key]) : num(totals?.[c.key])}</dd>
            </div>
          ))}
        </dl>
      </div>
    </div>
  );
}

export default TrafficExplorerPage;
