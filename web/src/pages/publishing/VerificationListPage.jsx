import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function VerificationListPage() {
  const [verifications, setVerifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('v1/publishing/verifications')
      .then((r) => {
        const list = Array.isArray(r) ? r : (Array.isArray(r?.items) ? r.items : (Array.isArray(r?.data) ? r.data : []));
        setVerifications(list);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const statusClass = s => ({
    passed: 'badge--success',
    failed: 'badge--error',
    skipped: 'badge--neutral',
    pending: 'badge--info',
    error: 'badge--error',
  }[s] ?? 'badge--neutral');

  if (loading) return <p className="muted">Loading verifications…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Verification Results</h1>
          <p className="page-header__subtitle">
            Post-publish checks for live URLs, sitemap entries, and structured data.
          </p>
        </div>
      </div>

      {verifications.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              No verification results yet. Results appear after a deployment is published.
            </p>
            <Link to="/publishing/deployments" className="stat-card__link">Open deployments →</Link>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Deployment</th>
                <th>Check</th>
                <th>Status</th>
                <th>Expected</th>
                <th>Actual</th>
                <th>Checked At</th>
              </tr>
            </thead>
            <tbody>
              {verifications.map(v => (
                <tr key={v.id}>
                  <td><Link to={`/publishing/deployments/${v.deployment_id}`}>#{v.deployment_id}</Link></td>
                  <td>{v.verification_type}</td>
                  <td><span className={`badge ${statusClass(v.status)}`}>{v.status}</span></td>
                  <td><code>{v.expected_value ?? '—'}</code></td>
                  <td><code>{v.actual_value ?? '—'}</code></td>
                  <td>{v.checked_at ? new Date(v.checked_at).toLocaleString() : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
