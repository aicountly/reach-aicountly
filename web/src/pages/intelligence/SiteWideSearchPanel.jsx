import { useEffect, useState } from 'react';
import {
  getSiteSearchDaily,
  listSiteSearchQueries,
  listSiteSearchPages,
  getSiteSearchPositions,
} from '../../services/intelligenceService.js';
import { DrillLink, useDrillFilters } from '../../components/drill';
import SearchExplorer from './SearchExplorer.jsx';
import { SEARCH_FILTER_KEYS } from './searchDrill.js';

function num(value) {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString() : '—';
}

function pct(value) {
  if (value == null) return '—';
  const n = Number(value);
  return Number.isFinite(n) ? `${(n * 100).toFixed(1)}%` : '—';
}

function pos(value) {
  if (value == null) return '—';
  const n = Number(value);
  return Number.isFinite(n) ? n.toFixed(1) : '—';
}

function shortUrl(url) {
  return String(url || '').replace(/^https?:\/\/(www\.)?/, '');
}

/**
 * Site-wide Search Console view: every row Google returns for the property
 * (homepage, product, pricing, guides and blogs), not only blog-mapped pages.
 * Every figure is an aggregate of ingested rows — an empty store renders an
 * explanation, never placeholder numbers.
 *
 * Every number drills down: a day, a position band, a query or a page opens
 * SearchExplorer narrowed to it (?date= / ?bucket= / ?query= / ?page=).
 */
export default function SiteWideSearchPanel({ configured }) {
  const drill = useDrillFilters(SEARCH_FILTER_KEYS);
  if (drill.depth > 0 || drill.params.get('by')) {
    return <SearchExplorer drill={drill} />;
  }
  return <SiteWideOverview configured={configured} drill={drill} />;
}

function SiteWideOverview({ configured, drill }) {
  const [state, setState] = useState({ loading: true, error: null, daily: null, queries: [], pages: [], buckets: [] });

  useEffect(() => {
    let cancelled = false;
    Promise.allSettled([
      getSiteSearchDaily(),
      listSiteSearchQueries({ limit: 25 }),
      listSiteSearchPages({ limit: 25 }),
      getSiteSearchPositions(),
    ]).then(([daily, queries, pages, positions]) => {
      if (cancelled) return;
      setState({
        loading: false,
        error: daily.status === 'rejected' ? (daily.reason?.message || 'Failed to load site-wide search data') : null,
        daily: daily.status === 'fulfilled' ? daily.value : null,
        queries: queries.status === 'fulfilled' ? (queries.value?.rows ?? []) : [],
        pages: pages.status === 'fulfilled' ? (pages.value?.rows ?? []) : [],
        buckets: positions.status === 'fulfilled' ? (positions.value?.buckets ?? []) : [],
      });
    });
    return () => { cancelled = true; };
  }, []);

  const { loading, error, daily, queries, pages, buckets } = state;

  if (loading) return <p className="text-sm text-muted">Loading site-wide search data…</p>;
  if (error) return <div className="alert alert-danger">{error}</div>;

  if (!daily?.available) {
    return (
      <div className="alert alert-info" data-testid="site-wide-empty">
        {daily?.message || 'No site-wide Search Console rows have been ingested yet.'}
        {configured
          ? ' Run “Backfill 90 days” above (or `php spark reach:search-console backfill-run` on the server) — Google publishes data with a ~3 day lag.'
          : ' Connect Search Console first — see docs/operations/SEARCH_CONSOLE_SETUP.md.'}
      </div>
    );
  }

  const totals = daily.totals || {};
  const days = daily.days || [];
  const maxClicks = Math.max(1, ...days.map((d) => Number(d.clicks) || 0));
  const totalBucketQueries = buckets.reduce((sum, b) => sum + (Number(b.queries) || 0), 0);

  return (
    <div data-testid="site-wide-panel">
      <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
        Whole property, {daily.period?.from} → {daily.period?.to} · freshest day {daily.latest_date}
      </p>

      <div className="grid grid-3" style={{ marginBottom: '1.25rem' }}>
        {[
          { label: 'Clicks', value: num(totals.clicks), go: { by: 'query', sort: 'clicks' } },
          { label: 'Impressions', value: num(totals.impressions), go: { by: 'query', sort: 'impressions' } },
          { label: 'CTR', value: pct(totals.ctr), go: { by: 'page', sort: 'ctr' } },
          { label: 'Avg position', value: pos(totals.avg_position), go: { by: 'query', sort: 'position' } },
          { label: 'Days with data', value: num(totals.days), go: { by: 'date' } },
          { label: 'Freshest day', value: daily.latest_date || '—', go: daily.latest_date ? { date: daily.latest_date, by: 'query' } : null },
        ].map((s) => (s.go ? (
          <button key={s.label} type="button" className="stat-tile" onClick={() => drill.drill(s.go)}>
            <div className="stat-tile__label">{s.label}</div>
            <div className="stat-tile__value">{s.value}</div>
            <div className="stat-tile__hint">View breakdown ›</div>
          </button>
        ) : (
          <div key={s.label} className="stat-tile">
            <div className="stat-tile__label">{s.label}</div>
            <div className="stat-tile__value">{s.value}</div>
          </div>
        )))}
      </div>

      <div className="card" style={{ marginBottom: '1.25rem' }}>
        <div className="card-body">
          <div className="flex items-center justify-between gap-2" style={{ flexWrap: 'wrap', margin: '0 0 0.75rem' }}>
            <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: 0 }}>Totals by day</h2>
            <span className="text-xs text-muted">Click a day to see the queries and pages behind it</span>
          </div>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th style={{ width: '40%' }}>Clicks</th>
                  <th style={{ textAlign: 'right' }}>Impressions</th>
                  <th style={{ textAlign: 'right' }}>CTR</th>
                  <th style={{ textAlign: 'right' }}>Avg position</th>
                </tr>
              </thead>
              <tbody>
                {[...days].reverse().map((d) => (
                  <tr key={d.date} className="is-drillable" onClick={() => drill.drill({ date: d.date, by: 'query' })}>
                    <td className="text-sm">
                      <DrillLink onClick={() => drill.drill({ date: d.date, by: 'query' })} title={`Queries and pages behind ${d.date}`}>
                        {d.date}
                      </DrillLink>
                    </td>
                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                        <div
                          aria-hidden="true"
                          style={{
                            height: '0.5rem',
                            width: `${Math.max(2, (Number(d.clicks) / maxClicks) * 100)}%`,
                            background: 'var(--color-info)',
                            borderRadius: '2px',
                          }}
                        />
                        <span className="text-sm">{num(d.clicks)}</span>
                      </div>
                    </td>
                    <td style={{ textAlign: 'right' }}>{num(d.impressions)}</td>
                    <td style={{ textAlign: 'right' }}>{pct(d.ctr)}</td>
                    <td style={{ textAlign: 'right' }}>{pos(d.avg_position)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div className="card" style={{ marginBottom: '1.25rem' }}>
        <div className="card-body">
          <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.75rem' }}>Position buckets (queries)</h2>
          <div className="grid grid-3">
            {buckets.map((b) => (
              <button
                key={b.bucket}
                type="button"
                className="stat-tile"
                disabled={!b.queries}
                onClick={() => drill.drill({ bucket: b.bucket, by: 'query' })}
                aria-label={`Position ${b.bucket}: ${b.queries} queries — view them`}
              >
                <div className="stat-tile__label">Position {b.bucket}</div>
                <div className="stat-tile__value">{num(b.queries)}</div>
                <div className="text-xs text-muted">
                  {totalBucketQueries > 0 ? `${Math.round((b.queries / totalBucketQueries) * 100)}% of queries · ` : ''}
                  {num(b.clicks)} clicks
                </div>
              </button>
            ))}
          </div>
        </div>
      </div>

      {[
        { title: 'Top queries', dim: 'query', next: 'page', rows: queries, label: (r) => r.dimension_value },
        { title: 'Top pages', dim: 'page', next: 'query', rows: pages, label: (r) => shortUrl(r.dimension_value) },
      ].map((block) => (
        <div key={block.title} className="card" style={{ marginBottom: '1.25rem' }}>
          <div className="card-body">
            <div className="flex items-center justify-between gap-2" style={{ flexWrap: 'wrap', margin: '0 0 0.75rem' }}>
              <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: 0 }}>{block.title}</h2>
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => drill.drill({ by: block.dim })}>
                View all {block.dim === 'query' ? 'queries' : 'pages'}
              </button>
            </div>
            {block.rows.length === 0 ? (
              <p className="text-sm text-muted" style={{ margin: 0 }}>No rows in this window.</p>
            ) : (
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{block.title === 'Top pages' ? 'Page' : 'Query'}</th>
                      <th style={{ textAlign: 'right' }}>Clicks</th>
                      <th style={{ textAlign: 'right' }}>Impressions</th>
                      <th style={{ textAlign: 'right' }}>CTR</th>
                      <th style={{ textAlign: 'right' }}>Avg position</th>
                    </tr>
                  </thead>
                  <tbody>
                    {block.rows.map((row) => (
                      <tr
                        key={row.dimension_value}
                        className="is-drillable"
                        onClick={() => drill.drill({ [block.dim]: row.dimension_value, by: block.next })}
                      >
                        <td style={{ fontWeight: 500, wordBreak: 'break-all' }}>
                          <DrillLink onClick={() => drill.drill({ [block.dim]: row.dimension_value, by: block.next })}>
                            {block.label(row)}
                          </DrillLink>
                        </td>
                        <td style={{ textAlign: 'right' }}>{num(row.clicks)}</td>
                        <td style={{ textAlign: 'right' }}>{num(row.impressions)}</td>
                        <td style={{ textAlign: 'right' }}>{pct(row.ctr)}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>{pos(row.avg_position)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}
