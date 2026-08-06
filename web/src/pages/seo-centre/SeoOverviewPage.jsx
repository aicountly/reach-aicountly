import { useEffect, useState } from 'react';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDate } from '../../utils/formatDate';

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
        <Card title="Clicks (28d)"><strong style={{ fontSize: '1.5rem' }}>{search.clicks ?? 0}</strong></Card>
        <Card title="Impressions (28d)"><strong style={{ fontSize: '1.5rem' }}>{search.impressions ?? 0}</strong></Card>
        <Card title="Avg position (28d)"><strong style={{ fontSize: '1.5rem' }}>{search.avg_position ? Number(search.avg_position).toFixed(1) : '—'}</strong></Card>
        <Card title="AI visibility runs (28d)"><strong style={{ fontSize: '1.5rem' }}>{data?.visibility_28d?.runs ?? 0}</strong></Card>
      </div>

      <Card title="Blog indexing status">
        <p>{statusList(data?.indexing_status, 'indexing_status')}</p>
      </Card>

      <Card title="IndexNow submissions">
        <p>{statusList(data?.indexnow_status, 'status')}</p>
        {!connections.indexnow && <p className="text-muted text-sm">Auto-submission is off (INDEXNOW_ENABLED=false).</p>}
      </Card>

      <Card title="Backlink health (latest snapshot)">
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

      <Card title="Recent rank snapshots (7d)">
        {(data?.recent_ranks || []).length === 0 ? (
          <p className="text-muted">No tracked-keyword data yet.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead><tr><th>Keyword</th><th>Date</th><th>Position</th><th>Clicks</th><th>Impressions</th></tr></thead>
              <tbody>
                {data.recent_ranks.map((row, i) => (
                  <tr key={`${row.keyword}-${row.metric_date}-${i}`}>
                    <td>{row.keyword}</td>
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
