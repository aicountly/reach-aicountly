import { useCallback, useEffect, useState } from 'react';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';

export function TrackedKeywordsPage() {
  const [keywords, setKeywords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [newKeyword, setNewKeyword] = useState('');
  const [saving, setSaving] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    api.get('v1/seo/tracked-keywords')
      .then((res) => setKeywords(res?.keywords ?? []))
      .catch((err) => setError(err?.message || 'Unable to load keywords.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const addKeyword = async (event) => {
    event.preventDefault();
    if (!newKeyword.trim()) return;
    setSaving(true);
    setError('');
    try {
      await api.post('v1/seo/keywords', { keyword: newKeyword.trim() });
      setNewKeyword('');
      load();
    } catch (err) {
      setError(err?.message || 'Could not add the keyword.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <Loader label="Loading tracked keywords…" />;

  return (
    <div>
      {error && <Alert variant="error">{error}</Alert>}
      <Card title="Track a keyword">
        <form onSubmit={addKeyword} style={{ display: 'flex', gap: '0.75rem' }}>
          <input
            value={newKeyword}
            onChange={(e) => setNewKeyword(e.target.value)}
            placeholder="e.g. gst reconciliation software"
            style={{ flex: 1 }}
          />
          <button type="submit" className="btn btn--primary" disabled={saving}>{saving ? 'Adding…' : 'Track'}</button>
        </form>
        <p className="text-muted text-sm" style={{ marginTop: '0.5rem' }}>
          Positions come from ingested Search Console data (daily 05:00 snapshot, ~2-day lag). No SERP scraping.
        </p>
      </Card>

      <Card title={`Tracked keywords (${keywords.length})`}>
        {keywords.length === 0 ? (
          <p className="text-muted">Nothing tracked yet.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead><tr><th>Keyword</th><th>Status</th><th>Latest position</th><th>Clicks</th><th>Impressions</th><th>Best page</th><th>As of</th></tr></thead>
              <tbody>
                {keywords.map((keyword) => {
                  const snap = keyword.latest_snapshot;
                  return (
                    <tr key={keyword.id}>
                      <td>{keyword.keyword}</td>
                      <td>{keyword.status}</td>
                      <td>{snap?.avg_position ? Number(snap.avg_position).toFixed(1) : '—'}</td>
                      <td>{snap?.clicks ?? '—'}</td>
                      <td>{snap?.impressions ?? '—'}</td>
                      <td className="text-sm" style={{ maxWidth: '20rem', overflow: 'hidden', textOverflow: 'ellipsis' }}>{snap?.best_page_url || '—'}</td>
                      <td className="text-sm">{snap?.metric_date || '—'}</td>
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

export default TrackedKeywordsPage;
