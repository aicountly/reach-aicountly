import { useCallback, useEffect, useState } from 'react';
import { mediaGalleryService } from '../../../services/mediaGalleryService';
import { Loader } from '../../../components/common/Loader';
import { Alert } from '../../../components/common/Alert';
import { Card } from '../../../components/common/Card';
import { formatDate, formatDateTime } from '../../../utils/formatDate';

const SYNC_LABELS = {
  synced: { label: 'Synced', className: 'badge badge--success' },
  pending_sync: { label: 'Pending sync', className: 'badge badge--warning' },
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
        The content base lives in the repo (<code>server-php/content-base/</code>) and is
        <strong> read-only here</strong>. Edit prompts and upcoming topics in git — every deploy
        overwrites the server copy (<code>rsync --delete</code>), and the daily Claude routine
        revises the index from product-repo changes.
      </Alert>

      <Card title={`Upcoming blog index (${entries.length} entries)`}>
        {lastSync ? (
          <p className="text-sm text-muted">
            Last DB sync: {formatDateTime(lastSync.ran_at)} — {lastSync.created_count} created, {lastSync.updated_count} updated,
            {' '}{lastSync.retired_count} retired of {lastSync.entries_seen} entries.
          </p>
        ) : (
          <Alert variant="warning">Never synced — run <code>php spark reach:content-base-sync</code> (also runs post-deploy and daily at 06:00).</Alert>
        )}
        <div style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Key</th><th>Title</th><th>Stream</th><th>Target</th><th>Status</th><th>Sync</th><th>Brief prompt</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => {
                const sync = SYNC_LABELS[entry.sync?.state] || SYNC_LABELS.pending_sync;
                return (
                  <tr key={entry.key}>
                    <td><code>{entry.key}</code></td>
                    <td>{entry.title}</td>
                    <td>{entry.portfolio_stream}</td>
                    <td>{formatDate(entry.target_date)}</td>
                    <td>{entry.status}</td>
                    <td><span className={sync.className}>{sync.label}</span></td>
                    <td className="text-sm text-muted" style={{ maxWidth: '28rem' }}>{entry.brief_prompt}</td>
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
