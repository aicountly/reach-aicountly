import { useState, useEffect } from 'react';

function StatCard({ label, value, variant }) {
  const valueStyle = {
    danger:  { color: 'var(--color-danger)' },
    warning: { color: 'var(--color-warning)' },
    success: { color: 'var(--color-success)' },
  }[variant];

  return (
    <div className={`stat-card${variant === 'warning' ? ' stat-card--warning' : ''}`}>
      <div className="stat-card__value" style={valueStyle}>{value}</div>
      <div className="stat-card__label">{label}</div>
    </div>
  );
}

export default function OperationsDashboardPage() {
  const [summary, _setSummary] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // TODO: fetch from /api/readiness/operations/summary
    setLoading(false);
  }, []);

  return (
    <div>
      <div className="page-header">
        <h1>Operations Dashboard</h1>
        <div className="page-header__actions">
          <button
            type="button"
            onClick={() => setLoading(true)}
            className="btn btn--sm btn--secondary"
          >
            Refresh
          </button>
        </div>
      </div>

      {loading ? (
        <p className="muted">Loading…</p>
      ) : !summary ? (
        <div className="stat-grid">
          <StatCard label="Recommendation Backlog" value="—" />
          <StatCard label="Active Workflows" value="—" />
          <StatCard label="Failed Publications" value="—" variant="danger" />
          <StatCard label="Pending Outcomes" value="—" />
          <StatCard label="Open Critical" value="—" variant="danger" />
          <StatCard label="Open High" value="—" variant="warning" />
        </div>
      ) : (
        <div className="stat-grid">
          <StatCard label="Recommendation Backlog" value={summary.recommendation_backlog} />
          <StatCard label="Active Workflows" value={summary.active_workflows} />
          <StatCard
            label="Failed Publications"
            value={summary.failed_publications}
            variant={summary.failed_publications > 0 ? 'danger' : 'success'}
          />
          <StatCard label="Pending Outcomes" value={summary.pending_outcome_windows} />
          <StatCard
            label="Open Critical"
            value={summary.open_critical_findings}
            variant={summary.open_critical_findings > 0 ? 'danger' : 'success'}
          />
          <StatCard
            label="Open High"
            value={summary.open_high_findings}
            variant={summary.open_high_findings > 0 ? 'warning' : 'success'}
          />
        </div>
      )}

      <div className="card" style={{ marginTop: '1.25rem' }}>
        <div className="card__header">Job Reliability (7 days)</div>
        <div className="card__body">
          <p className="text-sm text-muted" style={{ margin: 0 }}>
            Job reliability data will appear here once connected to the API.
          </p>
        </div>
      </div>
    </div>
  );
}
