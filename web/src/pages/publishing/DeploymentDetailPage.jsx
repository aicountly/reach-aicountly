import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../services/api';

export default function DeploymentDetailPage() {
  const { id } = useParams();
  const [deployment, setDeployment] = useState(null);
  const [verifications, setVerifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionMsg, setActionMsg] = useState(null);

  const load = () => {
    setLoading(true);
    Promise.all([
      api.get(`/publishing/deployments/${id}`),
      api.get(`/publishing/deployments/${id}/verifications`),
    ])
      .then(([dep, ver]) => {
        setDeployment(dep.data?.data ?? null);
        setVerifications(ver.data?.data ?? []);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(load, [id]);

  const handleAction = async (action) => {
    setActionMsg(null);
    try {
      await api.post(`/publishing/deployments/${id}/${action}`);
      setActionMsg(`${action} succeeded`);
      load();
    } catch (e) {
      setActionMsg(`${action} failed: ${e.message}`);
    }
  };

  if (loading) return <p className="muted">Loading deployment…</p>;
  if (error) return <p className="text-error">{error}</p>;
  if (!deployment) return <p className="muted">Deployment not found.</p>;

  const d = deployment;

  return (
    <div className="detail-page">
      <div className="page-header">
        <h1>Deployment #{d.id}</h1>
        <Link to="/publishing/deployments" className="btn btn--sm btn--secondary">← Back</Link>
      </div>

      {actionMsg && <p className="alert alert--info">{actionMsg}</p>}

      <div className="detail-grid">
        <div className="detail-grid__field">
          <span className="label">Content</span>
          <Link to={`/content/${d.content_item_id}`}>{d.content_title ?? `#${d.content_item_id}`}</Link>
        </div>
        <div className="detail-grid__field">
          <span className="label">Status</span>
          <span className={`badge badge--${d.status === 'verified' || d.status === 'published' ? 'success' : d.status === 'failed' ? 'error' : 'neutral'}`}>{d.status}</span>
        </div>
        <div className="detail-grid__field">
          <span className="label">Operation</span>
          <code>{d.operation}</code>
        </div>
        <div className="detail-grid__field">
          <span className="label">Canonical URL</span>
          {d.canonical_url
            ? <a href={d.canonical_url} target="_blank" rel="noopener noreferrer">{d.canonical_url}</a>
            : <span className="muted">—</span>}
        </div>
        <div className="detail-grid__field">
          <span className="label">Attempt Count</span>
          {d.attempt_count}
        </div>
        <div className="detail-grid__field">
          <span className="label">Idempotency Key</span>
          <code>{d.idempotency_key}</code>
        </div>
        {d.error_category && (
          <div className="detail-grid__field">
            <span className="label">Error</span>
            <span className="text-error">{d.error_category}: {d.redacted_error}</span>
          </div>
        )}
      </div>

      <div className="action-bar">
        {d.status === 'failed' && (
          <button className="btn btn--warning" onClick={() => handleAction('retry')}>Retry</button>
        )}
        {['published', 'verified'].includes(d.status) && (
          <button className="btn btn--error" onClick={() => handleAction('rollback')}>Rollback</button>
        )}
        {['accepted', 'published'].includes(d.status) && (
          <button className="btn" onClick={() => handleAction('verify')}>Verify Now</button>
        )}
        {d.status === 'failed' && (
          <button className="btn btn--secondary" onClick={() => handleAction('cancel')}>Cancel</button>
        )}
      </div>

      {verifications.length > 0 && (
        <section className="verifications-section">
          <h2>Verification Results</h2>
          <table className="data-table">
            <thead>
              <tr><th>Check</th><th>Status</th><th>Expected</th><th>Actual</th></tr>
            </thead>
            <tbody>
              {verifications.map(v => (
                <tr key={v.id}>
                  <td>{v.verification_type}</td>
                  <td>
                    <span className={`badge badge--${v.status === 'passed' ? 'success' : v.status === 'failed' ? 'error' : 'neutral'}`}>
                      {v.status}
                    </span>
                  </td>
                  <td><code>{v.expected_value ?? '—'}</code></td>
                  <td><code>{v.actual_value ?? '—'}</code></td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
    </div>
  );
}
