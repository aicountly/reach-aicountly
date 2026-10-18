import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { mediaGalleryService } from '../../../services/mediaGalleryService';
import { Loader } from '../../../components/common/Loader';
import { Alert } from '../../../components/common/Alert';
import { Card } from '../../../components/common/Card';
import { formatDate, formatDateTime } from '../../../utils/formatDate';

const STATUS_LABELS = {
  planned: { label: 'Planned', className: 'badge badge--warning' },
  queued: { label: 'Queued', className: 'badge badge--muted' },
  produced: { label: 'Produced', className: 'badge badge--info' },
  published: { label: 'Published', className: 'badge badge--success' },
  retired: { label: 'Retired', className: 'badge badge--muted' },
};

export function ContentBasePage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    mediaGalleryService.contentBase()
      .then((res) => { setData(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load the content base.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  if (loading) return <Loader label="Loading content base…" />;
  if (error) return <Alert variant="error">{error}</Alert>;

  const entries = data?.entries ?? [];
  const lastSync = data?.last_sync;

  return (
    <div>
      <Alert variant="info">
        Blog topics live in Reach and are updated by the routines daily through the API
        (<code>v1/automation/content-base/next · propose · produced</code>); roadmap-selected topics go
        first in the blog routine&apos;s queue. Git (<code>server-php/content-base/</code>) holds only the seed
        baseline, imported hourly. The full view, with knowledge base and community, is under{' '}
        <Link to="/quality/content-base">Quality Centre → Content Base</Link>.
        {data?.routine ? ` Last blog routine activity: ${data.routine.last_activity_at ? formatDateTime(data.routine.last_activity_at) : 'never'}.` : ''}
      </Alert>

      <Card title={`Blog topics (${entries.length})`}>
        {lastSync ? (
          <p className="text-sm text-muted">
            Last seed import that changed something: {formatDateTime(lastSync.ran_at)} — {lastSync.created_count} added, {lastSync.updated_count} updated,
            {' '}{lastSync.retired_count} retired of {lastSync.entries_seen} seeds.
          </p>
        ) : (
          <p className="text-sm text-muted">Seeds not imported yet — the hourly content-base sync imports them.</p>
        )}
        <div style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Key</th><th>Title</th><th>Stream</th><th>Target</th><th>Status</th><th>Origin</th><th>Brief / detail</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => {
                const status = STATUS_LABELS[entry.status] || STATUS_LABELS.planned;
                return (
                  <tr key={entry.key}>
                    <td><code>{entry.key}</code></td>
                    <td>{entry.title}</td>
                    <td>{entry.portfolio_stream}</td>
                    <td>{formatDate(entry.target_date)}</td>
                    <td><span className={status.className}>{status.label}</span></td>
                    <td className="text-sm">{entry.origin === 'routine' ? 'Routine' : 'Git seed'}</td>
                    <td className="text-sm text-muted" style={{ maxWidth: '28rem' }}>{entry.status_reason || entry.brief_prompt}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>

      <Card title="Strategy base (blog/base.md)">
        <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit', fontSize: '0.875rem' }}>
          {data?.base_markdown || 'base.md not found in the deployed content-base directory.'}
        </pre>
      </Card>
    </div>
  );
}
