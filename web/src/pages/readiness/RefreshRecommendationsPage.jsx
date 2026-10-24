import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { DataTable } from '../../components/common/DataTable';
import { Pagination } from '../../components/common/Pagination';
import { useUrlFilter } from '../../components/drill';
import { formatDate } from '../../utils/formatDate';
import { listRefreshRecommendations } from '../../services/readinessService.js';
import ContentCell from './RefreshContentCell.jsx';
import { RECOMMENDATION_STATUSES, RISKS, RISK_BADGE, confidence, label, score } from './refreshShared.js';

const LIMIT = 25;

/**
 * Refresh recommendations. The Operations "Recommendation Backlog" card links
 * here with ?backlog=1 — the server applies the same status scope the card
 * counted (recommended + triaged). Rows open the recommendation detail.
 */
export default function RefreshRecommendationsPage() {
  const navigate = useNavigate();
  const [status] = useUrlFilter('status');
  const [risk, setRisk] = useUrlFilter('risk');
  const [backlog, setBacklog] = useUrlFilter('backlog');
  const [identity, setIdentity] = useUrlFilter('content_identity_id');
  const [, setParams] = useSearchParams();
  const [page, setPage] = useState(1);

  // One URL update: picking a status replaces the backlog scope.
  const chooseStatus = (value) => setParams((prev) => {
    const next = new URLSearchParams(prev);
    next.delete('backlog');
    if (value) next.set('status', value); else next.delete('status');
    return next;
  }, { replace: true });
  const [state, setState] = useState({ loading: true, error: null, items: [], total: 0 });

  useEffect(() => { setPage(1); }, [status, risk, backlog, identity]);

  useEffect(() => {
    let cancelled = false;
    setState((s) => ({ ...s, loading: true, error: null }));
    const params = { page, limit: LIMIT };
    if (backlog === '1') params.backlog = '1';
    else if (status) params.status = status;
    if (risk) params.risk = risk;
    if (identity) params.content_identity_id = identity;
    listRefreshRecommendations(params)
      .then((d) => { if (!cancelled) setState({ loading: false, error: null, items: d?.items || [], total: d?.total ?? 0 }); })
      .catch((e) => { if (!cancelled) setState({ loading: false, error: e.message || 'Failed to load recommendations', items: [], total: 0 }); });
    return () => { cancelled = true; };
  }, [page, status, risk, backlog, identity]);

  const filtered = Boolean(status || risk || backlog || identity);
  const columns = [
    { key: 'id', label: '#' },
    { key: 'content', label: 'Content', render: (r) => <ContentCell row={r} /> },
    { key: 'risk_classification', label: 'Risk',
      render: (r) => <span className={`badge ${RISK_BADGE[r.risk_classification] ?? 'badge--neutral'}`}>{r.risk_classification}</span>,
      drill: (r) => (r.risk_classification !== risk ? { onClick: () => setRisk(r.risk_classification), title: 'Only this risk' } : null) },
    { key: 'status', label: 'Status', render: (r) => label(r.status),
      drill: (r) => (backlog !== '1' && r.status !== status ? { onClick: () => chooseStatus(r.status), title: 'Only this status' } : null) },
    { key: 'total_score', label: 'Score', render: (r) => score(r.total_score) },
    { key: 'confidence', label: 'Confidence', render: (r) => confidence(r.confidence) },
    { key: 'workflow', label: 'Workflow', render: (r) => (r.workflow_id ? `#${r.workflow_id} · ${label(r.workflow_status)}` : '—') },
    { key: 'created_at', label: 'Created', render: (r) => formatDate(r.created_at) },
  ];

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Refresh Recommendations</h1>
        <p className="page-header__subtitle">
          Evidence-scored content refresh candidates. Each score is the sum of its stored factor
          contributions — open a row to see them, the evidence snapshot and the workflow.
        </p>
      </div>

      <div className="filter-bar mb-4">
        <select value={backlog === '1' ? '' : status} onChange={(e) => chooseStatus(e.target.value)} aria-label="Status">
          <option value="">{backlog === '1' ? 'Backlog statuses' : 'All statuses'}</option>
          {RECOMMENDATION_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          {status && !RECOMMENDATION_STATUSES.includes(status) && <option value={status}>{label(status)}</option>}
        </select>
        <select value={risk} onChange={(e) => setRisk(e.target.value)} aria-label="Risk">
          <option value="">Any risk</option>
          {RISKS.map((r) => <option key={r} value={r}>{r}</option>)}
          {risk && !RISKS.includes(risk) && <option value={risk}>{label(risk)}</option>}
        </select>
        <Link to="/readiness/workflows" className="btn btn--sm btn--secondary">Workflows</Link>
      </div>

      {(backlog === '1' || identity) && (
        <div className="mb-4" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {backlog === '1' && (
            <button type="button" className="badge badge--neutral" onClick={() => setBacklog('')} aria-label="Clear backlog filter">
              Backlog (recommended + triaged) ✕
            </button>
          )}
          {identity && (
            <button type="button" className="badge badge--neutral" onClick={() => setIdentity('')} aria-label={`Clear content identity ${identity} filter`}>
              Content identity #{identity} ✕
            </button>
          )}
        </div>
      )}

      {state.error && <div className="alert alert-danger mb-4">{state.error}</div>}

      <div className="card">
        {state.loading ? (
          <div className="card__body"><p className="muted" style={{ margin: 0 }}>Loading recommendations…</p></div>
        ) : (
          <DataTable
            columns={columns}
            rows={state.items}
            onRowClick={(r) => navigate(`/readiness/recommendations/${r.id}`)}
            rowLabel={(r) => `Open recommendation ${r.id}`}
            emptyMessage={filtered
              ? 'No recommendations match this filter.'
              : 'No refresh recommendations yet. They are produced when an evidence snapshot scores above the policy threshold.'}
          />
        )}
      </div>
      <p className="text-xs text-muted">{state.total} recommendation{state.total === 1 ? '' : 's'}</p>
      <Pagination page={page} limit={LIMIT} total={state.total} onPage={setPage} />
    </div>
  );
}
