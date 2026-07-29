import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listTechnicalDebt } from '../../services/readinessService.js';

const BLOCKER_CLASSES = new Set(['critical_blocker', 'high_blocker']);

export default function TechnicalDebtPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    setLoading(true);
    listTechnicalDebt()
      .then((data) => setRows(Array.isArray(data) ? data : []))
      .catch((e) => setError(e.message || 'Failed to load technical debt'))
      .finally(() => setLoading(false));
  }, []);

  const openBlockers = rows.filter(
    (r) => BLOCKER_CLASSES.has(r.classification) && !r.accepted_by,
  );

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Technical Debt</h1>
        <p className="page-header__subtitle">
          Classified technical debt items. Critical and high blockers must be resolved
          or formally accepted before release.
        </p>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      <div className="stat-grid mb-4">
        <div className={`stat-card${openBlockers.length ? ' stat-card--warning' : ''}`}>
          <div className="stat-card__value">{loading ? '…' : openBlockers.length}</div>
          <div className="stat-card__label">Open debt blockers</div>
          <Link to="/readiness/release" className="stat-card__link">Release acceptance →</Link>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{loading ? '…' : rows.length}</div>
          <div className="stat-card__label">Total debt records</div>
        </div>
      </div>

      <div className="card">
        {loading ? (
          <div className="card__body"><p className="muted" style={{ margin: 0 }}>Loading…</p></div>
        ) : rows.length === 0 ? (
          <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No technical debt records created yet. Empty blockers means this gate can pass.
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Classification</th>
                <th>Title</th>
                <th>Accepted</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id}>
                  <td>
                    <span className={`badge ${BLOCKER_CLASSES.has(r.classification) && !r.accepted_by ? 'badge--warning' : 'badge--neutral'}`}>
                      {r.classification}
                    </span>
                  </td>
                  <td className="td--primary">{r.title}</td>
                  <td>{r.accepted_by ? 'Yes' : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
