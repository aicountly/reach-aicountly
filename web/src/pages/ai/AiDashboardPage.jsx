import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { getAiDashboard } from '../../services/aiService.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';

export default function AiDashboardPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    getAiDashboard()
      .then(setData)
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading AI dashboard…</p>;
  if (error)   return <p className="text-error">Error: {error}</p>;

  const stats = data?.stats || {};
  const recentRequests = data?.recent_requests || [];
  const todayCost = stats.today_cost != null
    ? `$${Number(stats.today_cost).toFixed(4)}`
    : '$0.0000';

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Dashboard</h1>
        <p className="page-header__subtitle">Generation volume, failures, and spend for today.</p>
      </div>

      <div className="stat-grid">
        <div className="stat-card">
          <div className="stat-card__value">{stats.total_generations ?? 0}</div>
          <div className="stat-card__label">Total Generations</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{stats.completed_today ?? 0}</div>
          <div className="stat-card__label">Completed Today</div>
        </div>
        <div className="stat-card stat-card--warning">
          <div className="stat-card__value">{stats.failed_today ?? 0}</div>
          <div className="stat-card__label">Failed Today</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{todayCost}</div>
          <div className="stat-card__label">Today&apos;s Cost</div>
        </div>
      </div>

      {recentRequests.length > 0 && (
        <section className="card mt-4">
          <div className="card__header">
            <h2 className="card__title">Recent Generation Requests</h2>
          </div>
          <div className="card__body" style={{ padding: 0, overflowX: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th>UUID</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                {recentRequests.map(r => (
                  <tr key={r.uuid}>
                    <td>
                      <Link to={`/ai/generations/${r.uuid}`} className="text-sm">
                        {r.uuid?.slice(0, 8)}…
                      </Link>
                    </td>
                    <td>{r.content_type}</td>
                    <td><AiGenerationBadge status={r.status} /></td>
                    <td className="text-muted text-xs">{r.created_at}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}
