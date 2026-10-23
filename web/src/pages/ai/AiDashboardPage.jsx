import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { getAiDashboard } from '../../services/aiService.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';
import { DrillLink, drillHref } from '../../components/drill';
import { formatDateTime } from '../../utils/formatDate';

// Zero is not a link: there is nothing behind it to open.
function StatCard({ value, label, to, warning = false, count = value }) {
  return (
    <div className={`stat-card${warning ? ' stat-card--warning' : ''}`}>
      <div className="stat-card__value">{value}</div>
      <div className="stat-card__label">{label}</div>
      {Number(count) > 0 && (
        <Link to={to} className="stat-card__link" aria-label={`View ${label.toLowerCase()}`}>View →</Link>
      )}
    </div>
  );
}

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

      {/* Each count opens the records it counted, with the same server-side
          predicate (created_on / completed_on resolve "today" on the API). */}
      <div className="stat-grid">
        <StatCard value={stats.total_generations ?? 0} label="Total Generations" to="/ai/generations" />
        <StatCard
          value={stats.completed_today ?? 0}
          label="Completed Today"
          to={drillHref('/ai/generations', { status: 'completed', completed_on: 'today' })}
        />
        <StatCard
          value={stats.failed_today ?? 0}
          label="Failed Today"
          warning
          to={drillHref('/ai/generations', { status: 'failed', created_on: 'today' })}
        />
        <StatCard
          value={todayCost}
          label="Today's Cost"
          count={Number(stats.today_cost) || 0}
          to={drillHref('/ai/usage', { date: 'today' })}
        />
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
                    <td>
                      <DrillLink to={r.content_type ? drillHref('/ai/generations', { content_type: r.content_type }) : undefined}>
                        {r.content_type}
                      </DrillLink>
                    </td>
                    <td><AiGenerationBadge status={r.status} /></td>
                    <td className="text-muted text-xs">{formatDateTime(r.created_at)}</td>
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
