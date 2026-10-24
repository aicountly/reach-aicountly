import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { BarChart2 } from 'lucide-react';
import { listContentMetrics } from '../../services/intelligenceService.js';
import { DrillLink, drillHref } from '../../components/drill';
import { num, pct, pos, shortUrl } from './searchDrill.js';

/**
 * Search Console performance per Reach content identity
 * (GET v1/intelligence/content/metrics → ContentSearchPerformanceService):
 * site-wide Search Console rows whose page URL maps to a content identity,
 * over the Search explorer's window (28 days to the freshest day). A row
 * opens that content's daily rows. GA4 engagement is not connected, so no
 * sessions are shown.
 */
export default function ContentPerformancePage() {
  const [data, setData] = useState({ rows: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const navigate = useNavigate();

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listContentMetrics()
      .then((d) => {
        if (cancelled) return;
        // An array is the pre-search-facts shape; keep reading it.
        setData(Array.isArray(d) ? { rows: d } : { ...d, rows: d?.rows || [] });
      })
      .catch((e) => { if (!cancelled) setError(e.message || 'Failed to load content performance'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  const { rows, period, unmapped, available } = data;

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <BarChart2 size={26} style={{ color: 'var(--color-primary)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Content Performance</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Search Console clicks and impressions per Reach content
            {period ? ` · ${period.from} → ${period.to}` : ''}
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {loading && <p className="text-sm text-muted">Loading content performance…</p>}

      {!loading && rows.length === 0 && !error && (
        <div className="card">
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              {available === false
                ? 'No Search Console rows have been ingested yet. Connect Search Console and run an ingest to populate this page.'
                : 'No ingested Search Console page maps to a Reach content identity in this window.'}
            </p>
          </div>
        </div>
      )}

      {!loading && rows.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Content</th>
                <th style={{ textAlign: 'right' }}>Clicks</th>
                <th style={{ textAlign: 'right' }}>Impressions</th>
                <th style={{ textAlign: 'right' }}>CTR</th>
                <th style={{ textAlign: 'right' }}>Avg position</th>
                <th style={{ textAlign: 'right' }}>Days</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((c, i) => {
                const identityId = c.content_identity_id ?? c.identity_id;
                const name = c.title || c.content_title || shortUrl(c.url) || 'Untitled';
                const open = identityId ? () => navigate(`/intelligence/content/${identityId}`) : undefined;
                return (
                  <tr
                    key={identityId || c.url || i}
                    className={open ? 'is-drillable' : undefined}
                    tabIndex={open ? 0 : undefined}
                    aria-label={open ? `Open analytics for ${name}` : undefined}
                    onClick={open}
                    onKeyDown={open ? (e) => {
                      if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(); }
                    } : undefined}
                  >
                    <td>
                      <p style={{ fontWeight: 600, margin: 0 }}>{name}</p>
                      <p className="text-xs text-muted" style={{ margin: '0.15rem 0 0', fontFamily: 'monospace' }}>
                        {shortUrl(c.url) || '—'}
                        {(c.page_urls?.length ?? 0) > 1 ? ` (+${c.page_urls.length - 1} more URL${c.page_urls.length > 2 ? 's' : ''})` : ''}
                      </p>
                      {c.content_item_id && (
                        <span className="text-xs" onClick={(e) => e.stopPropagation()} role="presentation">
                          <DrillLink to={`/content/${c.content_item_id}`} title="Open the content item">Content item #{c.content_item_id}</DrillLink>
                        </span>
                      )}
                    </td>
                    <td style={{ textAlign: 'right', fontWeight: 600 }}>{num(c.clicks)}</td>
                    <td style={{ textAlign: 'right' }}>{num(c.impressions)}</td>
                    <td style={{ textAlign: 'right' }}>{pct(c.ctr)}</td>
                    <td style={{ textAlign: 'right' }}>{pos(c.avg_position)}</td>
                    <td style={{ textAlign: 'right' }}>{c.days ?? '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {!loading && unmapped?.pages > 0 && (
        <p className="text-xs text-muted" style={{ marginTop: '0.75rem' }}>
          {num(unmapped.pages)} other page{unmapped.pages === 1 ? '' : 's'} ({num(unmapped.clicks)} clicks) belong to no Reach content
          identity — see them in the <DrillLink to={drillHref('/seo-centre/search', { by: 'page' })}>site-wide Search explorer</DrillLink>.
          Figures come from query-level rows, so they can sit below property totals.
        </p>
      )}
    </div>
  );
}
