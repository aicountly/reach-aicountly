import { useEffect, useState } from 'react';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDate } from '../../utils/formatDate';
import { DrillLink, drillHref } from '../../components/drill';

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
                {snapshots.map((snap) => {
                  // Each figure opens the snapshot with the records it counted.
                  const at = (kind, n) => (Number(n) > 0 ? drillHref(`/seo-centre/backlinks/${snap.id}`, { kind }) : undefined);
                  return (
                    <tr key={snap.id}>
                      <td>
                        <DrillLink to={`/seo-centre/backlinks/${snap.id}`} title="Open this snapshot">
                          {formatDate(snap.snapshot_date)}
                        </DrillLink>
                      </td>
                      <td>{snap.provider}</td>
                      <td><DrillLink to={at('internal_links', snap.internal_links)} title="Internal links counted">{snap.internal_links}</DrillLink></td>
                      <td><DrillLink to={at('published_urls', snap.published_urls)} title="Published URLs counted">{snap.published_urls}</DrillLink></td>
                      <td>
                        <DrillLink to={Number(snap.total_backlinks) > 0 ? `/seo-centre/backlinks/${snap.id}` : undefined} title="Backlink rows in this snapshot">
                          {snap.total_backlinks ?? '—'}
                        </DrillLink>
                      </td>
                      <td>
                        <DrillLink to={Number(snap.referring_domains) > 0 ? `/seo-centre/backlinks/${snap.id}` : undefined} title="Referring domains in this snapshot">
                          {snap.referring_domains ?? '—'}
                        </DrillLink>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

export default BacklinksPage;
