import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Zap, Send, RefreshCw, CheckCircle, Clock, Shield, XCircle, AlertTriangle } from 'lucide-react';
import { submitIndexNowUrl, retryIndexNowPending } from '../../services/intelligenceService.js';
import { seoService } from '../../services/seoService';
import { formatDateTime } from '../../utils/formatDate';
import { drillHref } from '../../components/drill';

const SUBMISSIONS = '/seo-centre/indexnow/submissions';

export default function IndexNowOperationsPage() {
  const navigate = useNavigate();
  const [url, setUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [submissions, setSubmissions] = useState([]);
  const [counts, setCounts] = useState(null);

  // Tiles and the recent list come from the stored submissions, so every
  // number opens the same records on the submissions page.
  const load = useCallback(() => seoService.listIndexNowSubmissions({ per_page: 10 })
    .then((res) => {
      setSubmissions(res?.submissions || []);
      setCounts(res?.counts || null);
    })
    // Without seo.view the tiles stay '—' rather than raising an error.
    .catch((err) => { if (err?.status !== 403) setError(err?.message || 'Unable to load IndexNow submissions'); }), []);

  useEffect(() => { load(); }, [load]);

  const statusBadge = (status) => {
    const map = {
      submitted: 'badge--success',
      retrying: 'badge--warning',
      failed: 'badge--danger',
      pending: 'badge--info',
    };
    return <span className={`badge ${map[status] ?? 'badge--muted'}`}>{status}</span>;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!url.trim()) return;
    setSubmitting(true);
    setError(null);
    setResult(null);
    try {
      const res = await submitIndexNowUrl({ url: url.trim(), tenant_id: 1 });
      setResult(res?.data || res || { url: url.trim() });
      setUrl('');
      load();
    } catch (err) {
      setError(err.message || 'Submission failed');
    } finally {
      setSubmitting(false);
    }
  };

  const handleRetry = async () => {
    setRetrying(true);
    setError(null);
    try {
      await retryIndexNowPending();
      setResult({ status: 'retrying', url: 'pending submissions' });
      load();
    } catch (err) {
      setError(err.message || 'Retry failed');
    } finally {
      setRetrying(false);
    }
  };

  return (
    <div>
      <div className="page-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <Zap size={22} style={{ color: 'var(--color-warning)', flexShrink: 0 }} aria-hidden="true" />
          <div>
            <h1 style={{ margin: 0 }}>IndexNow Operations</h1>
            <p className="page-header__subtitle" style={{ marginTop: '0.15rem' }}>
              Submit URLs to search engine indexes via IndexNow protocol
            </p>
          </div>
        </div>
        <div className="page-header__actions">
          <button type="button" className="btn btn--sm btn--secondary" onClick={handleRetry} disabled={retrying}>
            <RefreshCw size={14} aria-hidden="true" />
            {retrying ? 'Retrying…' : 'Retry pending'}
          </button>
        </div>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      <div className="stat-grid mb-4">
        {[
          { label: 'All submissions', value: counts?.total, status: '', icon: Zap, color: 'var(--color-primary)' },
          { label: 'Submitted', value: counts?.submitted, status: 'submitted', icon: CheckCircle, color: 'var(--color-success)' },
          { label: 'Pending / retrying', value: counts ? (counts.retrying ?? 0) + (counts.pending ?? 0) : undefined, status: 'retrying,pending', icon: AlertTriangle, color: 'var(--color-warning)' },
          { label: 'Failed', value: counts?.failed, status: 'failed', icon: XCircle, color: 'var(--color-danger)' },
        ].map((stat) => {
          const body = (
            <>
              <stat.icon size={20} style={{ color: stat.color, flexShrink: 0 }} aria-hidden="true" />
              <div>
                <div className="stat-card__value">{stat.value ?? '—'}</div>
                <div className="stat-card__label">{stat.label}</div>
              </div>
            </>
          );
          const style = { display: 'flex', alignItems: 'center', gap: '0.75rem' };
          return Number(stat.value) > 0 ? (
            <Link key={stat.label} to={drillHref(SUBMISSIONS, { status: stat.status })} className="stat-card stat-tile"
                  style={{ ...style, textDecoration: 'none', color: 'inherit' }} aria-label={`${stat.label}: ${stat.value}`}>
              {body}
            </Link>
          ) : (
            <div key={stat.label} className="stat-card" style={style}>{body}</div>
          );
        })}
      </div>

      <div
        className="mb-4"
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))',
          gap: '1rem',
          alignItems: 'stretch',
        }}
      >
        <div className="card" style={{ minWidth: 0 }}>
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0 }}>Submit URL</h2>
          </div>
          <div className="card__body">
            <form onSubmit={handleSubmit}>
              <label className="text-xs text-secondary" htmlFor="indexnow-url" style={{ display: 'block', marginBottom: '0.35rem' }}>
                Canonical page URL
              </label>
              <div style={{ display: 'flex', gap: '0.6rem', alignItems: 'stretch', flexWrap: 'wrap' }}>
                <input
                  id="indexnow-url"
                  type="url"
                  value={url}
                  onChange={(e) => setUrl(e.target.value)}
                  placeholder="https://example.com/blog/my-post"
                  required
                  style={{ flex: '1 1 220px', minWidth: 0 }}
                />
                <button
                  type="submit"
                  disabled={submitting || !url.trim()}
                  className="btn btn--primary"
                  style={{ flex: '0 0 auto', minWidth: '7.5rem' }}
                >
                  <Send size={14} aria-hidden="true" />
                  {submitting ? 'Submitting…' : 'Submit'}
                </button>
              </div>
            </form>
            {result && (
              <p className="text-sm" style={{ margin: '0.85rem 0 0', display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
                <CheckCircle size={14} style={{ color: 'var(--color-success)', flexShrink: 0 }} aria-hidden="true" />
                {result.status === 'retrying'
                  ? 'Retry queued for pending submissions'
                  : `URL submitted: ${result.url}`}
              </p>
            )}
          </div>
        </div>

        <div className="card" style={{ minWidth: 0 }}>
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0, display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}>
              <Shield size={15} aria-hidden="true" />
              Endpoint policy
            </h2>
          </div>
          <div className="card__body">
            <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
              Submissions are only accepted to allowlisted IndexNow endpoints.
            </p>
            <ul style={{ margin: 0, paddingLeft: '1.1rem', fontSize: '0.82rem', color: 'var(--color-text-secondary)', lineHeight: 1.7 }}>
              <li><code>api.indexnow.org</code></li>
              <li><code>www.bing.com</code></li>
              <li><code>search.google.com</code></li>
            </ul>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card__header">
          <h2 className="card__title" style={{ margin: 0 }}>Recent Submissions</h2>
          {Number(counts?.total) > 0 && <Link to={SUBMISSIONS} className="text-sm">All {counts.total} ›</Link>}
        </div>
        {submissions.length === 0 ? (
          <div className="card__body">
            <p className="muted" style={{ margin: 0 }}>
              No IndexNow submissions recorded yet. Submit a URL above to see results here.
            </p>
          </div>
        ) : (
          <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th style={{ width: '48%' }}>URL</th>
                  <th style={{ width: '14%' }}>Status</th>
                  <th style={{ width: '12%' }}>Attempts</th>
                  <th style={{ width: '26%' }}>Submitted</th>
                </tr>
              </thead>
              <tbody>
                {submissions.map((s) => (
                  <tr key={s.id} className="is-drillable" tabIndex={0} aria-label={`Open submission ${s.url}`}
                      onClick={() => navigate(`${SUBMISSIONS}/${s.id}`)}
                      onKeyDown={(e) => { if (e.key === 'Enter') navigate(`${SUBMISSIONS}/${s.id}`); }}>
                    <td className="text-sm" style={{ fontFamily: 'var(--font-mono, monospace)', wordBreak: 'break-all' }}>
                      {s.url}
                    </td>
                    <td>{statusBadge(s.status || 'pending')}</td>
                    <td>{s.attempt_count ?? 0}</td>
                    <td className="text-sm text-muted">
                      {s.submitted_at
                        ? formatDateTime(s.submitted_at)
                        : (
                          <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                            <Clock size={12} aria-hidden="true" />
                            —
                          </span>
                        )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
