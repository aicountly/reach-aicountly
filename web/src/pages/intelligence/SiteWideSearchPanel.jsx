import { useEffect, useState } from 'react';
import {
  getSiteSearchDaily,
  listSiteSearchQueries,
  listSiteSearchPages,
  getSiteSearchPositions,
} from '../../services/intelligenceService.js';

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
 */
export default function SiteWideSearchPanel({ configured }) {
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
          { label: 'Clicks', value: num(totals.clicks) },
          { label: 'Impressions', value: num(totals.impressions) },
          { label: 'CTR', value: pct(totals.ctr) },
          { label: 'Avg position', value: pos(totals.avg_position) },
          { label: 'Days with data', value: num(totals.days) },
          { label: 'Freshest day', value: daily.latest_date || '—' },
        ].map((s) => (
          <div key={s.label} className="stat-tile">
            <div className="stat-tile__label">{s.label}</div>
            <div className="stat-tile__value">{s.value}</div>
          </div>
        ))}
      </div>

      <div className="card" style={{ marginBottom: '1.25rem' }}>
        <div className="card-body">
          <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.75rem' }}>Totals by day</h2>
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
                  <tr key={d.date}>
                    <td className="text-sm">{d.date}</td>
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
              <div key={b.bucket} className="stat-tile">
                <div className="stat-tile__label">Position {b.bucket}</div>
                <div className="stat-tile__value">{num(b.queries)}</div>
                <div className="text-xs text-muted">
                  {totalBucketQueries > 0 ? `${Math.round((b.queries / totalBucketQueries) * 100)}% of queries · ` : ''}
                  {num(b.clicks)} clicks
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {[
        { title: 'Top queries', rows: queries, label: (r) => r.dimension_value },
        { title: 'Top pages', rows: pages, label: (r) => shortUrl(r.dimension_value) },
      ].map((block) => (
        <div key={block.title} className="card" style={{ marginBottom: '1.25rem' }}>
          <div className="card-body">
            <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.75rem' }}>{block.title}</h2>
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
                      <tr key={row.dimension_value}>
                        <td style={{ fontWeight: 500, wordBreak: 'break-all' }}>{block.label(row)}</td>
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
