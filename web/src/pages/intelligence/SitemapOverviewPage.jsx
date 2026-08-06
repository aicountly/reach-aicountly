import { useEffect, useState } from 'react';
import { Map, RefreshCw, CheckCircle, XCircle, AlertTriangle } from 'lucide-react';
import { getLatestSitemap, generateSitemapSnapshot } from '../../services/intelligenceService.js';
import { formatDateTime } from '../../utils/formatDate';

function StatusIcon({ status }) {
  if (status === 'validated' || status === 'generated') {
    return <CheckCircle size={18} style={{ color: 'var(--color-success)' }} aria-hidden="true" />;
  }
  if (status === 'failed') {
    return <XCircle size={18} style={{ color: 'var(--color-danger)' }} aria-hidden="true" />;
  }
  return <AlertTriangle size={18} style={{ color: 'var(--color-warning)' }} aria-hidden="true" />;
}

export default function SitemapOverviewPage() {
  const [snapshot, setSnapshot] = useState(null);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState(null);
  const [message, setMessage] = useState(null);

  const load = () => {
    setLoading(true);
    setError(null);
    getLatestSitemap()
      .then((res) => {
        // api.js unwraps `data` when non-null; when data is null the full envelope is returned.
        if (res && typeof res === 'object' && 'data' in res && 'message' in res) {
          setSnapshot(res.data ?? null);
          setMessage(res.message || null);
          return;
        }
        setSnapshot(res ?? null);
        setMessage(null);
      })
      .catch((e) => setError(e.message || 'Failed to load sitemap snapshot'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  const handleGenerate = async () => {
    setGenerating(true);
    setError(null);
    try {
      const res = await generateSitemapSnapshot({ tenant_id: 1 });
      if (res && typeof res === 'object' && 'data' in res) {
        setSnapshot(res.data ?? null);
        setMessage(res.message || 'Snapshot generated');
      } else {
        setSnapshot(res ?? null);
        setMessage('Snapshot generated');
      }
    } catch (e) {
      setError(e.message || 'Unable to generate snapshot');
    } finally {
      setGenerating(false);
    }
  };

  const stats = [
    { label: 'Total Entries', value: snapshot?.total_entries ?? 0 },
    { label: 'Included', value: snapshot?.included_entries ?? 0 },
    { label: 'Excluded (noindex)', value: snapshot?.excluded_noindex ?? 0 },
    { label: 'Excluded (withdrawn)', value: snapshot?.excluded_withdrawn ?? 0 },
  ];

  return (
    <div>
      <div className="page-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <Map size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} aria-hidden="true" />
          <div>
            <h1 style={{ margin: 0 }}>Sitemap Intelligence</h1>
            <p className="page-header__subtitle" style={{ marginTop: '0.15rem' }}>
              Internal sitemap snapshots from canonical content identities
            </p>
          </div>
        </div>
        <div className="page-header__actions">
          <button
            type="button"
            onClick={handleGenerate}
            disabled={generating}
            className="btn btn--sm btn--primary"
          >
            <RefreshCw size={14} aria-hidden="true" />
            {generating ? 'Generating…' : 'Generate Snapshot'}
          </button>
        </div>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}
      {loading && <p className="muted">Loading latest snapshot…</p>}

      {!loading && !snapshot && (
        <div className="card mb-4">
          <div className="card__body">
            <p className="muted" style={{ margin: 0 }}>
              {message || 'No snapshot found yet. Generate a snapshot to populate sitemap intelligence.'}
            </p>
          </div>
        </div>
      )}

      {!loading && snapshot && (
        <div className="card mb-4">
          <div className="card__header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '1rem' }}>
            <h2 className="card__title" style={{ margin: 0 }}>Latest Snapshot</h2>
            <div style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}>
              <StatusIcon status={snapshot.status} />
              <span className="text-sm" style={{ textTransform: 'capitalize', fontWeight: 600 }}>
                {snapshot.status || 'unknown'}
              </span>
            </div>
          </div>
          <div className="card__body">
            <div className="stat-grid">
              {stats.map((s) => (
                <div key={s.label} className="stat-card">
                  <div className="stat-card__value">{s.value}</div>
                  <div className="stat-card__label">{s.label}</div>
                </div>
              ))}
            </div>
            <p className="text-sm text-muted" style={{ margin: '1rem 0 0' }}>
              {snapshot.generated_at
                ? `Generated ${formatDateTime(snapshot.generated_at)}`
                : 'Generated time unavailable'}
              {snapshot.generation_secs != null ? ` · ${snapshot.generation_secs}s` : ''}
            </p>
          </div>
        </div>
      )}

      <div className="card">
        <div className="card__header">
          <h2 className="card__title" style={{ margin: 0 }}>Exclusion Policy</h2>
        </div>
        <div className="card__body">
          <ul style={{ margin: 0, paddingLeft: '1.1rem' }} className="text-sm">
            <li>Withdrawn content is always excluded</li>
            <li>Content with noindex publication status is excluded</li>
            <li>Unpublished and private content is excluded</li>
            <li>Analytics-ineligible identities are excluded</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
