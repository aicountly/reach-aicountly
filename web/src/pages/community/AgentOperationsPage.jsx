import { useCallback, useEffect, useState } from 'react';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';

const STATUS_BADGES = {
  success: 'badge badge--success',
  blocked: 'badge badge--warning',
  failed: 'badge badge--danger',
};

export function AgentOperationsPage() {
  const [status, setStatus] = useState(null);
  const [runs, setRuns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [runFilter, setRunFilter] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([
      api.get('v1/community/agents/status'),
      api.get('v1/community/agents/runs', runFilter ? { status: runFilter } : undefined),
    ])
      .then(([statusRes, runsRes]) => {
        setStatus(statusRes);
        setRuns(runsRes?.runs ?? []);
        setError('');
      })
      .catch((err) => setError(err?.message || 'Unable to load agent operations.'))
      .finally(() => setLoading(false));
  }, [runFilter]);

  useEffect(load, [load]);

  if (loading) return <Loader label="Loading agent operations…" />;

  return (
    <div>
      {error && <Alert variant="error">{error}</Alert>}

      <Alert variant={status?.window_open ? 'success' : 'info'}>
        Automation window (09:00–19:00 IST) is currently{' '}
        <strong>{status?.window_open ? 'OPEN' : 'CLOSED'}</strong>. Content-creating actions
        (curate, answer, comment) only run inside the window; the cron trigger is
        <code> community:agents-run</code> every 30 minutes. Every identity posts with a mandatory
        AI disclosure, and vote/like automation does not exist by design.
      </Alert>

      <Card title="Identities, caps and today's usage">
        <div style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr><th>Identity</th><th>Role</th><th>Action</th><th>Used today</th><th>Daily cap</th><th>Window-gated</th></tr>
            </thead>
            <tbody>
              {(status?.identities ?? []).flatMap((identity) =>
                identity.actions.map((action, index) => (
                  <tr key={`${identity.slug}-${action.action}`}>
                    {index === 0 && (
                      <>
                        <td rowSpan={identity.actions.length}><strong>{identity.display_name}</strong><div className="text-muted text-sm">{identity.slug}</div></td>
                        <td rowSpan={identity.actions.length}>{identity.role}</td>
                      </>
                    )}
                    <td>{action.action}</td>
                    <td>{action.used_today}</td>
                    <td>{action.daily_cap ?? '—'}</td>
                    <td>{action.window_gated ? 'yes' : 'no'}</td>
                  </tr>
                )),
              )}
            </tbody>
          </table>
        </div>
      </Card>

      <Card title="Run history">
        <div style={{ marginBottom: '0.75rem' }}>
          <label>
            <span className="text-muted" style={{ marginRight: '0.5rem' }}>Status</span>
            <select value={runFilter} onChange={(e) => setRunFilter(e.target.value)}>
              <option value="">All</option>
              <option value="success">success</option>
              <option value="blocked">blocked</option>
              <option value="failed">failed</option>
            </select>
          </label>
          <button type="button" className="btn btn--secondary" style={{ marginLeft: '0.75rem' }} onClick={load}>Refresh</button>
        </div>
        {runs.length === 0 ? (
          <p className="text-muted">
            No runs yet. The cron trigger (<code>community:agents-run</code>) selects work automatically;
            manual dispatch is available from the Identities page or the API.
          </p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr><th>When</th><th>Identity</th><th>Action</th><th>Status</th><th>Detail</th></tr>
              </thead>
              <tbody>
                {runs.map((run) => (
                  <tr key={run.id}>
                    <td className="text-sm">{run.created_at}</td>
                    <td>{run.identity_name || run.identity_slug || run.identity_id}</td>
                    <td>{run.action}</td>
                    <td><span className={STATUS_BADGES[run.outcome] || 'badge'}>{run.outcome}</span></td>
                    <td className="text-sm text-muted">{run.block_reason || ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

export default AgentOperationsPage;
