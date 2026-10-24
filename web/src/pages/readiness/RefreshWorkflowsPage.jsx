import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { DataTable } from '../../components/common/DataTable';
import { Pagination } from '../../components/common/Pagination';
import { DrillLink, useUrlFilter } from '../../components/drill';
import { formatDate } from '../../utils/formatDate';
import { listRefreshWorkflows } from '../../services/readinessService.js';
import ContentCell from './RefreshContentCell.jsx';
import { RISK_BADGE, WORKFLOW_STATUSES, label } from './refreshShared.js';

const LIMIT = 25;

/**
 * Refresh workflows. The Operations "Active Workflows" card links here with
 * ?active=1 (every status except the closed ones, the card's own scope).
 * A row opens its recommendation, which shows the workflow's brief,
 * publication and outcome windows.
 */
export default function RefreshWorkflowsPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useUrlFilter('status');
  const [active, setActive] = useUrlFilter('active');
  const [identity, setIdentity] = useUrlFilter('content_identity_id');
  const [recommendation, setRecommendation] = useUrlFilter('recommendation_id');
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, items: [], total: 0 });

  useEffect(() => { setPage(1); }, [status, active, identity, recommendation]);

  useEffect(() => {
    let cancelled = false;
    setState((s) => ({ ...s, loading: true, error: null }));
    const params = { page, limit: LIMIT };
    if (status) params.status = status;
    if (active === '1') params.active = '1';
    if (identity) params.content_identity_id = identity;
    if (recommendation) params.recommendation_id = recommendation;
    listRefreshWorkflows(params)
      .then((d) => { if (!cancelled) setState({ loading: false, error: null, items: d?.items || [], total: d?.total ?? 0 }); })
      .catch((e) => { if (!cancelled) setState({ loading: false, error: e.message || 'Failed to load workflows', items: [], total: 0 }); });
    return () => { cancelled = true; };
  }, [page, status, active, identity, recommendation]);

  const filtered = Boolean(status || active || identity || recommendation);
  const columns = [
    { key: 'id', label: '#' },
    { key: 'content', label: 'Content', render: (r) => <ContentCell row={r} /> },
    { key: 'status', label: 'Status', render: (r) => label(r.status),
      drill: (r) => (r.status !== status ? { onClick: () => setStatus(r.status), title: 'Only this status' } : null) },
    { key: 'risk_classification', label: 'Risk', render: (r) => (r.risk_classification
      ? <span className={`badge ${RISK_BADGE[r.risk_classification] ?? 'badge--neutral'}`}>{r.risk_classification}</span>
      : '—') },
    { key: 'refresh_objective', label: 'Objective', render: (r) => r.refresh_objective || '—' },
    { key: 'recommendation_id', label: 'Recommendation', render: (r) => (
      <span onClick={(e) => e.stopPropagation()} role="presentation">
        <DrillLink to={`/readiness/recommendations/${r.recommendation_id}`}>#{r.recommendation_id}</DrillLink>
        <span className="text-xs text-muted"> {label(r.recommendation_status)}</span>
      </span>
    ) },
    { key: 'due_date', label: 'Due', render: (r) => (r.due_date ? formatDate(r.due_date) : '—') },
    { key: 'updated_at', label: 'Updated', render: (r) => formatDate(r.updated_at) },
  ];

  const chips = [
    active === '1' && { key: 'active', text: 'Active only', clear: () => setActive('') },
    identity && { key: 'identity', text: `Content identity #${identity}`, clear: () => setIdentity('') },
    recommendation && { key: 'rec', text: `Recommendation #${recommendation}`, clear: () => setRecommendation('') },
  ].filter(Boolean);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Refresh Workflows</h1>
        <p className="page-header__subtitle">
          Accepted recommendations moving through brief → draft → review → publish → outcome.
        </p>
      </div>

      <div className="filter-bar mb-4">
        <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
          <option value="">All statuses</option>
          {WORKFLOW_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          {status && !WORKFLOW_STATUSES.includes(status) && <option value={status}>{label(status)}</option>}
        </select>
        <Link to="/readiness/recommendations" className="btn btn--sm btn--secondary">Recommendations</Link>
      </div>

      {chips.length > 0 && (
        <div className="mb-4" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {chips.map((c) => (
            <button key={c.key} type="button" className="badge badge--neutral" onClick={c.clear} aria-label={`Clear ${c.text} filter`}>
              {c.text} ✕
            </button>
          ))}
        </div>
      )}

      {state.error && <div className="alert alert-danger mb-4">{state.error}</div>}

      <div className="card">
        {state.loading ? (
          <div className="card__body"><p className="muted" style={{ margin: 0 }}>Loading workflows…</p></div>
        ) : (
          <DataTable
            columns={columns}
            rows={state.items}
            onRowClick={(r) => navigate(`/readiness/recommendations/${r.recommendation_id}#workflow-${r.id}`)}
            rowLabel={(r) => `Open workflow ${r.id}`}
            emptyMessage={filtered ? 'No workflows match this filter.' : 'No refresh workflows yet. One starts when a recommendation is accepted.'}
          />
        )}
      </div>
      <p className="text-xs text-muted">{state.total} workflow{state.total === 1 ? '' : 's'}</p>
      <Pagination page={page} limit={LIMIT} total={state.total} onPage={setPage} />
    </div>
  );
}
