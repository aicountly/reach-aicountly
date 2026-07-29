import { useMemo, useState } from 'react';
import { Zap, Send, RefreshCw, CheckCircle, Clock, Shield, XCircle, AlertTriangle } from 'lucide-react';
import { submitIndexNowUrl, retryIndexNowPending } from '../../services/intelligenceService.js';

export default function IndexNowOperationsPage() {
  const [url, setUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [submissions, setSubmissions] = useState([]);

  const counts = useMemo(() => {
    const tally = { total: submissions.length, submitted: 0, retrying: 0, failed: 0, pending: 0 };
    for (const row of submissions) {
      const key = (row.status || 'submitted').toLowerCase();
      if (key in tally) tally[key] += 1;
      else tally.submitted += 1;
    }
    return tally;
  }, [submissions]);

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
      const row = res?.data || { url: url.trim(), status: 'submitted', attempt_count: 1, submitted_at: new Date().toISOString() };
      setResult(row);
      setSubmissions((prev) => [{ id: row.id || Date.now(), ...row, url: row.url || url.trim() }, ...prev]);
      setUrl('');
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
          { label: 'Session total', value: counts.total, icon: Zap, color: 'var(--color-primary)' },
          { label: 'Submitted', value: counts.submitted, icon: CheckCircle, color: 'var(--color-success)' },
          { label: 'Retrying', value: counts.retrying + counts.pending, icon: AlertTriangle, color: 'var(--color-warning)' },
          { label: 'Failed', value: counts.failed, icon: XCircle, color: 'var(--color-danger)' },
        ].map((stat) => (
          <div key={stat.label} className="stat-card" style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <stat.icon size={20} style={{ color: stat.color, flexShrink: 0 }} aria-hidden="true" />
            <div>
              <div className="stat-card__value">{stat.value}</div>
              <div className="stat-card__label">{stat.label}</div>
            </div>
          </div>
        ))}
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
          <span className="text-sm text-muted">{counts.total} in this session</span>
        </div>
        {submissions.length === 0 ? (
          <div className="card__body">
            <p className="muted" style={{ margin: 0 }}>
              No submissions in this session yet. Submit a URL above to see results here.
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
                  <tr key={s.id}>
                    <td className="text-sm" style={{ fontFamily: 'var(--font-mono, monospace)', wordBreak: 'break-all' }}>
                      {s.url}
                    </td>
                    <td>{statusBadge(s.status || 'submitted')}</td>
                    <td>{s.attempt_count ?? 1}</td>
                    <td className="text-sm text-muted">
                      {s.submitted_at
                        ? new Date(s.submitted_at).toLocaleString()
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
