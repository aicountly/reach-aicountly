import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { BarChart2 } from 'lucide-react';
import api from '../../services/api.js';
import { DrillLink, drillHref } from '../../components/drill';
import { formatDate } from '../../utils/formatDate';
import { num, pct, pos, shortUrl } from './searchDrill.js';

const SEARCH = '/seo-centre/search';

/**
 * One content identity's Search Console rows per day × page URL
 * (GET v1/intelligence/content/metrics/:id). A row opens the site-wide
 * Search explorer at that page and day, which breaks it down by query →
 * device → country down to the single Search Console row.
 */
export default function ContentDetailAnalyticsPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    api.get(`v1/intelligence/content/metrics/${id}`)
      .then((d) => { if (!cancelled) setData(d || null); })
      .catch((e) => { if (!cancelled) setError(e.message || 'Failed to load content analytics'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [id]);

  const content = data?.content;
  const summary = data?.summary;
  const daily = Array.isArray(data?.daily) ? data.daily : [];
  const name = content?.title || shortUrl(content?.canonical_url) || `Identity #${id}`;
  const explore = (row) => drillHref(SEARCH, { page: row.page_url, date: row.date, by: 'query' });

  const tiles = [
    ['Clicks', num(summary?.clicks ?? 0)],
    ['Impressions', num(summary?.impressions ?? 0)],
    ['CTR', pct(summary?.ctr)],
    ['Avg position', pos(summary?.avg_position)],
  ];

  return (
    <div>
      <p className="text-sm" style={{ margin: '0 0 0.5rem' }}><Link to="/intelligence/content">← Content performance</Link></p>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <BarChart2 size={26} style={{ color: 'var(--color-primary)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>{loading ? 'Content Analytics' : name}</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Identity #{id}
            {content?.content_type ? ` · ${content.content_type.replace(/_/g, ' ')}` : ''}
            {data?.period ? ` · Search Console ${data.period.from} → ${data.period.to}` : ''}
          </p>
          {content && (
            <p className="text-xs" style={{ margin: '0.25rem 0 0' }}>
              {content.content_item_id && <><DrillLink to={`/content/${content.content_item_id}`}>Content item #{content.content_item_id}</DrillLink> · </>}
              <DrillLink to={drillHref('/readiness/recommendations', { content_identity_id: id })}>Refresh recommendations</DrillLink>
              {' · '}
              <DrillLink to={drillHref('/readiness/outcomes', { content_identity_id: id })}>Refresh outcomes</DrillLink>
            </p>
          )}
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      <div className="grid grid-4" style={{ marginBottom: '1.25rem' }}>
        {tiles.map(([label, value]) => (
          <div key={label} className="stat-tile">
            <div className="stat-tile__label">{label}</div>
            <div className="stat-tile__value">{loading ? '…' : value}</div>
          </div>
        ))}
      </div>

      <div className="card">
        <div className="card-header">Daily rows (day × page)</div>
        <div className="card-body">
          {loading && <p className="text-sm text-muted" style={{ margin: 0 }}>Loading…</p>}
          {!loading && daily.length === 0 && !error && (
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              {data?.latest_date
                ? 'No Search Console rows for this content in this window.'
                : 'No Search Console rows have been ingested yet.'}
            </p>
          )}
          {!loading && daily.length > 0 && (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Page</th>
                    <th style={{ textAlign: 'right' }}>Clicks</th>
                    <th style={{ textAlign: 'right' }}>Impressions</th>
                    <th style={{ textAlign: 'right' }}>CTR</th>
                    <th style={{ textAlign: 'right' }}>Avg position</th>
                    <th style={{ textAlign: 'right' }}>Query rows</th>
                  </tr>
                </thead>
                <tbody>
                  {daily.map((f) => (
                    <tr
                      key={`${f.date}-${f.page_url}`}
                      className="is-drillable"
                      tabIndex={0}
                      aria-label={`Queries for ${shortUrl(f.page_url)} on ${formatDate(f.date)}`}
                      onClick={() => navigate(explore(f))}
                      onKeyDown={(e) => {
                        if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); navigate(explore(f)); }
                      }}
                    >
                      <td>{formatDate(f.date)}</td>
                      <td className="text-xs" style={{ fontFamily: 'monospace' }}>{shortUrl(f.page_url)}</td>
                      <td style={{ textAlign: 'right', fontWeight: 600 }}>{num(f.clicks)}</td>
                      <td style={{ textAlign: 'right' }}>{num(f.impressions)}</td>
                      <td style={{ textAlign: 'right' }}>{pct(f.ctr)}</td>
                      <td style={{ textAlign: 'right' }}>{pos(f.avg_position)}</td>
                      <td style={{ textAlign: 'right' }}>{num(f.rows)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
