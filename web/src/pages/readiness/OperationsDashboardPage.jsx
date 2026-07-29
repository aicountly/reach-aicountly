import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { usePermission } from '../../hooks/usePermission';
import {
  ensureOperationalCheckDefaults,
  getOperationsSummary,
  listOperationalChecks,
  upsertOperationalCheck,
} from '../../services/readinessService.js';

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

const STATUS_BADGE = {
  passed: 'badge--success',
  not_applicable: 'badge--neutral',
  failed: 'badge--danger',
  skipped: 'badge--muted',
  pending: 'badge--warning',
};

export default function OperationsDashboardPage() {
  const { has } = usePermission();
  const canManage = has('readiness.manage');

  const [summary, setSummary] = useState(null);
  const [jobs, setJobs] = useState([]);
  const [checks, setChecks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = () => {
    setLoading(true);
    setError(null);
    Promise.all([getOperationsSummary(), listOperationalChecks()])
      .then(([ops, checkRows]) => {
        setSummary(ops?.summary || null);
        setJobs(Array.isArray(ops?.jobs?.job_types) ? ops.jobs.job_types : []);
        setChecks(Array.isArray(checkRows) ? checkRows : []);
      })
      .catch((e) => setError(e.message || 'Failed to load operations'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const handleEnsureDefaults = async () => {
    if (!canManage) return;
    setBusy(true);
    setError(null);
    try {
      const res = await ensureOperationalCheckDefaults();
      setChecks(Array.isArray(res?.checks) ? res.checks : []);
    } catch (e) {
      setError(e.message || 'Failed to seed default checks');
    } finally {
      setBusy(false);
    }
  };

  const markStatus = async (check, status) => {
    if (!canManage) return;
    setBusy(true);
    setError(null);
    try {
      await upsertOperationalCheck({
        check_category: check.check_category,
        check_name: check.check_name,
        status,
        evidence: check.evidence || `Marked ${status} from Operations dashboard`,
      });
      load();
    } catch (e) {
      setError(e.message || 'Failed to update check');
      setBusy(false);
    }
  };

  return (
    <div>
      <div className="page-header">
        <h1>Operations Dashboard</h1>
        <div className="page-header__actions">
          <Link to="/readiness/release" className="btn btn--sm btn--secondary">Release acceptance</Link>
          <button type="button" onClick={load} className="btn btn--sm btn--secondary">
            Refresh
          </button>
        </div>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      {loading ? (
        <p className="muted">Loading…</p>
      ) : (
        <div className="stat-grid mb-4">
          <StatCard label="Recommendation Backlog" value={summary?.recommendation_backlog ?? 0} />
          <StatCard label="Active Workflows" value={summary?.active_workflows ?? 0} />
          <StatCard
            label="Failed Publications"
            value={summary?.failed_publications ?? 0}
            variant={(summary?.failed_publications ?? 0) > 0 ? 'danger' : 'success'}
          />
          <StatCard label="Pending Outcomes" value={summary?.pending_outcome_windows ?? 0} />
          <StatCard
            label="Open Critical"
            value={summary?.open_critical_findings ?? 0}
            variant={(summary?.open_critical_findings ?? 0) > 0 ? 'danger' : 'success'}
          />
          <StatCard
            label="Open High"
            value={summary?.open_high_findings ?? 0}
            variant={(summary?.open_high_findings ?? 0) > 0 ? 'warning' : 'success'}
          />
        </div>
      )}

      <section className="card mb-4">
        <div className="card__header">
          <h2 className="card__title" style={{ margin: 0 }}>
            Operational readiness checks
          </h2>
          {canManage && (
            <button
              type="button"
              className="btn btn--sm btn--secondary"
              onClick={handleEnsureDefaults}
              disabled={busy}
            >
              Ensure defaults
            </button>
          )}
        </div>
        {checks.length === 0 ? (
          <div className="card__body">
            <p className="muted" style={{ margin: 0 }}>
              No operational checks yet.
              {canManage
                ? ' Click “Ensure defaults” to seed deployment/backup/rollback checks required for release acceptance.'
                : ' An operator with readiness.manage can seed the default checklist.'}
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Category</th>
                <th>Check</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {checks.map((c) => (
                <tr key={`${c.check_category}:${c.check_name}`}>
                  <td>{c.check_category}</td>
                  <td>{c.check_name.replace(/_/g, ' ')}</td>
                  <td>
                    <span className={`badge ${STATUS_BADGE[c.status] ?? 'badge--muted'}`}>
                      {c.status}
                    </span>
                  </td>
                  <td>
                    {canManage && c.status !== 'passed' && (
                      <button
                        type="button"
                        className="btn btn--sm btn--primary"
                        disabled={busy}
                        onClick={() => markStatus(c, 'passed')}
                      >
                        Mark passed
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <div className="card">
        <div className="card__header">Job Reliability (7 days)</div>
        <div className="card__body">
          {jobs.length === 0 ? (
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No job reliability data in the last 7 days.
            </p>
          ) : (
            <table className="data-table data-table--compact">
              <thead>
                <tr>
                  <th>Job type</th>
                  <th>Total</th>
                  <th>Completed</th>
                  <th>Failed</th>
                </tr>
              </thead>
              <tbody>
                {jobs.map((j) => (
                  <tr key={j.job_type}>
                    <td>{j.job_type}</td>
                    <td>{j.total}</td>
                    <td>{j.completed}</td>
                    <td>{j.failed}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
