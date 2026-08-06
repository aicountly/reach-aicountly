import { useEffect, useState } from 'react';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDate } from '../../utils/formatDate';

export function BacklinksPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('v1/seo/backlinks')
      .then(setData)
      .catch((err) => setError(err?.message || 'Unable to load backlink snapshots.'))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loader label="Loading backlink health…" />;
  if (error) return <Alert variant="error">{error}</Alert>;

  const snapshots = data?.snapshots ?? [];

  return (
    <div>
      {data?.provider_configured === 'internal_signals' && (
        <Alert variant="info">
          Running on the free internal-signals provider (internal link graph + published-URL counts).
          External backlink counts and referring domains need a paid index — add DATAFORSEO_LOGIN /
          DATAFORSEO_PASSWORD to the server .env to enable the DataForSEO adapter.
        </Alert>
      )}

      <Card title="Daily snapshots">
        {snapshots.length === 0 ? (
          <p className="text-muted">No snapshots yet — the daily 05:00 job writes the first one.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead><tr><th>Date</th><th>Provider</th><th>Internal links</th><th>Published URLs</th><th>External backlinks</th><th>Referring domains</th></tr></thead>
              <tbody>
                {snapshots.map((snap) => (
                  <tr key={snap.id}>
                    <td>{formatDate(snap.snapshot_date)}</td>
                    <td>{snap.provider}</td>
                    <td>{snap.internal_links}</td>
                    <td>{snap.published_urls}</td>
                    <td>{snap.total_backlinks ?? '—'}</td>
                    <td>{snap.referring_domains ?? '—'}</td>
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

export default BacklinksPage;
