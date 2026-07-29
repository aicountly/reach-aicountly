import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listFindingBlockers, listFindings } from '../../services/readinessService.js';

const SEVERITY_BADGE = {
  critical: 'badge--danger',
  high: 'badge--warning',
  medium: 'badge--info',
  low: 'badge--neutral',
};

export default function SecurityStatusPage() {
  const [findings, setFindings] = useState([]);
  const [blockers, setBlockers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    setLoading(true);
    Promise.all([listFindings(), listFindingBlockers()])
      .then(([all, open]) => {
        setFindings(Array.isArray(all) ? all : []);
        setBlockers(Array.isArray(open) ? open : []);
      })
      .catch((e) => setError(e.message || 'Failed to load findings'))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Security Status</h1>
        <p className="page-header__subtitle">
          Open security findings from readiness audit runs. Critical and high findings
          must be resolved or risk-accepted before release.
        </p>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      <div className="stat-grid mb-4">
        <div className={`stat-card${blockers.length ? ' stat-card--warning' : ''}`}>
          <div className="stat-card__value">{loading ? '…' : blockers.length}</div>
          <div className="stat-card__label">Open critical/high blockers</div>
          <Link to="/readiness/release" className="stat-card__link">Release acceptance →</Link>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{loading ? '…' : findings.length}</div>
          <div className="stat-card__label">Total findings loaded</div>
        </div>
      </div>

      <div className="card">
        {loading ? (
          <div className="card__body"><p className="muted" style={{ margin: 0 }}>Loading findings…</p></div>
        ) : findings.length === 0 ? (
          <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No audit findings recorded yet. Empty blockers means this gate can pass for release acceptance.
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Severity</th>
                <th>Title</th>
                <th>Category</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {findings.map((f) => (
                <tr key={f.id}>
                  <td>
                    <span className={`badge ${SEVERITY_BADGE[f.severity] ?? 'badge--neutral'}`}>
                      {f.severity}
                    </span>
                  </td>
                  <td className="td--primary">{f.title}</td>
                  <td>{f.category ?? '—'}</td>
                  <td>{f.resolution_status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
