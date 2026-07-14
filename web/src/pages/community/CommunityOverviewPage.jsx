import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function CommunityOverviewPage() {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/community/analytics/overview')
      .then(r => setStats(r.data?.data ?? {}))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading overview…</p>;
  if (error) return <p className="text-error">{error}</p>;

  const q = stats.questions_by_status ?? {};
  const a = stats.answers_by_status ?? {};

  return (
    <div>
      <div className="page-header">
        <h1>Community Control Centre</h1>
        <p className="page-header__subtitle">Official Q&amp;A automation dashboard</p>
      </div>

      <div className="stat-grid">
        <div className="stat-card">
          <div className="stat-card__value">{q.new ?? 0}</div>
          <div className="stat-card__label">New questions</div>
          <Link to="/community/questions?status=new" className="stat-card__link">View →</Link>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{stats.pending_approval ?? 0}</div>
          <div className="stat-card__label">Pending approval</div>
          <Link to="/community/answers?status=pending_approval" className="stat-card__link">Review →</Link>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{stats.published_answers ?? 0}</div>
          <div className="stat-card__label">Published answers</div>
          <Link to="/community/answers?status=published" className="stat-card__link">View →</Link>
        </div>
        <div className="stat-card stat-card--warning">
          <div className="stat-card__value">{stats.open_moderation_flags ?? 0}</div>
          <div className="stat-card__label">Open moderation flags</div>
          <Link to="/community/moderation" className="stat-card__link">Resolve →</Link>
        </div>
      </div>

      <div className="two-col-grid mt-4">
        <section className="card">
          <h2 className="card__title">Questions by status</h2>
          <table className="data-table data-table--compact">
            <tbody>
              {Object.entries(q).map(([status, cnt]) => (
                <tr key={status}>
                  <td><span className="badge badge--neutral">{status}</span></td>
                  <td className="text-right">{cnt}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
        <section className="card">
          <h2 className="card__title">Answers by status</h2>
          <table className="data-table data-table--compact">
            <tbody>
              {Object.entries(a).map(([status, cnt]) => (
                <tr key={status}>
                  <td><span className="badge badge--neutral">{status}</span></td>
                  <td className="text-right">{cnt}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      </div>
    </div>
  );
}
