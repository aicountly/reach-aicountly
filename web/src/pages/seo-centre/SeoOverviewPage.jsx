import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDate } from '../../utils/formatDate';
import { DrillLink, drillHref } from '../../components/drill';

export function SeoOverviewPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('v1/seo/overview')
      .then((res) => setData(res))
      .catch((err) => setError(err?.message || 'Unable to load SEO overview.'))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loader label="Loading SEO overview…" />;
  if (error) return <Alert variant="error">{error}</Alert>;

  const search = data?.search_28d || {};
  const connections = data?.connections || {};
  const statusList = (rows, keyName) =>
    (rows || []).map((row) => `${row[keyName] || 'unknown'}: ${row.c}`).join(' · ') || '—';

  return (
    <div>
      {!connections.search_console && (
        <Alert variant="warning">
          Search Console ingestion is disabled (SEARCH_CONSOLE_ENABLED=false) — search metrics,
          rank tracking and CTR suggestions stay empty until it is connected.
        </Alert>
      )}

      <div className="stat-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '1rem', marginBottom: '1rem' }}>
        {[
          // Search Console explorer, sorted the way each number is read.
          { title: 'Clicks (28d)', value: search.clicks ?? 0, to: drillHref('/seo-centre/search', { by: 'query', sort: 'clicks' }), count: Number(search.clicks ?? 0) },
          { title: 'Impressions (28d)', value: search.impressions ?? 0, to: drillHref('/seo-centre/search', { by: 'query', sort: 'impressions' }), count: Number(search.impressions ?? 0) },
          { title: 'Avg position (28d)', value: search.avg_position ? Number(search.avg_position).toFixed(1) : '—', to: drillHref('/seo-centre/search', { by: 'query', sort: 'position' }), count: search.avg_position ? 1 : 0 },
          { title: 'AI visibility runs (28d)', value: data?.visibility_28d?.runs ?? 0, to: '/intelligence/visibility', count: Number(data?.visibility_28d?.runs ?? 0) },
        ].map((t) => {
          const card = <Card title={t.title}><strong style={{ fontSize: '1.5rem' }}>{t.value}</strong></Card>;
          return t.count > 0
            ? <Link key={t.title} to={t.to} className="kpi-card-link" aria-label={`${t.title}: ${t.value}`}>{card}</Link>
            : <div key={t.title}>{card}</div>;
        })}
      </div>

      <Card title="Blog indexing status">
        <p>{statusList(data?.indexing_status, 'indexing_status')}</p>
      </Card>

      <Card title="IndexNow submissions" actions={<Link to="/seo-centre/indexnow" className="text-sm">IndexNow ›</Link>}>
        <p>{statusList(data?.indexnow_status, 'status')}</p>
        {!connections.indexnow && <p className="text-muted text-sm">Auto-submission is off (INDEXNOW_ENABLED=false).</p>}
      </Card>

      <Card title="Backlink health (latest snapshot)" actions={<Link to="/seo-centre/backlinks" className="text-sm">All snapshots ›</Link>}>
        {data?.backlinks ? (
          <p>
            Provider: {data.backlinks.provider} · Internal links: {data.backlinks.internal_links} ·
            Published URLs: {data.backlinks.published_urls}
            {data.backlinks.total_backlinks != null && <> · External backlinks: {data.backlinks.total_backlinks}</>}
            {data.backlinks.referring_domains != null && <> · Referring domains: {data.backlinks.referring_domains}</>}
          </p>
        ) : (
          <p className="text-muted">No snapshot yet — the daily 05:00 job writes the first one.</p>
        )}
      </Card>

      <Card title="Recent rank snapshots (7d)" actions={<Link to="/seo-centre/keywords" className="text-sm">Tracked keywords ›</Link>}>
        {(data?.recent_ranks || []).length === 0 ? (
          <p className="text-muted">No tracked-keyword data yet.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead><tr><th>Keyword</th><th>Date</th><th>Position</th><th>Clicks</th><th>Impressions</th></tr></thead>
              <tbody>
                {data.recent_ranks.map((row, i) => (
                  <tr key={`${row.keyword}-${row.metric_date}-${i}`}>
                    <td>
                      <DrillLink to={drillHref('/seo-centre/search', { query: row.keyword, by: 'page' })} title="Pages ranking for this keyword">
                        {row.keyword}
                      </DrillLink>
                    </td>
                    <td>{formatDate(row.metric_date)}</td>
                    <td>{row.avg_position ? Number(row.avg_position).toFixed(1) : '—'}</td>
                    <td>{row.clicks}</td>
                    <td>{row.impressions}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

export default SeoOverviewPage;
