import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { ROUTES } from '../../constants/routes';
import { DataTable } from '../../components/common/DataTable';
import { Pagination } from '../../components/common/Pagination';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';
import { DrillLink, drillHref, useUrlFilter } from '../../components/drill';
import { formatDateTime } from '../../utils/formatDate';

const LIMIT = 25;
const STATUSES = ['pending', 'eligible', 'queued', 'processing', 'completed', 'failed', 'blocked', 'cancelled'];
const label = (v) => String(v ?? '').replace(/,/g, ' + ').replace(/_/g, ' ');

const contentHref = (r) => (r.content_item_id ? `${ROUTES.CONTENT}/${r.content_item_id}` : null);
const decisionHref = (r) => (r.optimizer_run_id
  ? drillHref(ROUTES.BCC_ROADMAP_OPTIMIZER, { run_id: r.optimizer_run_id, has_work_block: 1 })
  : null);

/**
 * reach_work_blocks — the units of AI work the blog pipeline schedules.
 * The Overview's AI Ops "work blocks queued" links here with ?status=queued
 * (the same predicate it counted); ?run_id= lists the blocks one optimiser
 * run's decisions queued. A row opens its content item, or the decision that
 * queued it when it has none.
 */
export function BlogWorkBlocksPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useUrlFilter('status');
  const [runId, setRunId] = useUrlFilter('run_id');
  const [blockType, setBlockType] = useUrlFilter('block_type');
  const [contentId, setContentId] = useUrlFilter('content_item_id');
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, items: [], total: 0 });

  useEffect(() => { setPage(1); }, [status, runId, blockType, contentId]);

  useEffect(() => {
    let cancelled = false;
    setState((s) => ({ ...s, loading: true, error: null }));
    const params = { page, limit: LIMIT, status, run_id: runId, block_type: blockType, content_item_id: contentId };
    api.get('v1/blog-command-centre/work-blocks', params)
      .then((d) => { if (!cancelled) setState({ loading: false, error: null, items: d?.items || [], total: d?.total ?? 0 }); })
      .catch((e) => { if (!cancelled) setState({ loading: false, error: e.message || 'Failed to load work blocks', items: [], total: 0 }); });
    return () => { cancelled = true; };
  }, [page, status, runId, blockType, contentId]);

  const columns = [
    { key: 'id', label: '#' },
    { key: 'block_type', label: 'Block', render: (r) => <code style={{ fontSize: '0.75rem' }}>{r.block_type}</code>,
      drill: (r) => (r.block_type !== blockType ? { onClick: () => setBlockType(r.block_type), title: 'Only this block type' } : null) },
    { key: 'eligibility_status', label: 'Status', render: (r) => <StatusBadge status={r.eligibility_status} />,
      drill: (r) => (r.eligibility_status !== status ? { onClick: () => setStatus(r.eligibility_status), title: 'Only this status' } : null) },
    { key: 'content', label: 'Content', render: (r) => (r.content_item_id
      ? <span onClick={(e) => e.stopPropagation()} role="presentation"><DrillLink to={contentHref(r)}>{r.content_title || `Item #${r.content_item_id}`}</DrillLink>{r.content_status ? <span className="text-xs text-muted"> · {label(r.content_status)}</span> : null}</span>
      : '—') },
    { key: 'decision', label: 'Queued by', render: (r) => (r.decision_id
      ? <span onClick={(e) => e.stopPropagation()} role="presentation"><DrillLink to={decisionHref(r)} title="That run's decisions">{label(r.decision)} · run #{r.optimizer_run_id}</DrillLink></span>
      : '—') },
    { key: 'attempts', label: 'Attempts', render: (r) => `${r.attempt_count ?? 0} / ${r.max_attempts ?? '—'}` },
    { key: 'job', label: 'Job', render: (r) => (r.job_id
      ? <span onClick={(e) => e.stopPropagation()} role="presentation"><DrillLink to={`${ROUTES.JOBS}/${r.job_id}`}>#{r.job_id}</DrillLink></span>
      : '—') },
    { key: 'failure', label: 'Failure', render: (r) => (r.failure_classification ? <span className="text-danger text-xs">{label(r.failure_classification)}</span> : '—') },
    { key: 'scheduled_at', label: 'Scheduled', render: (r) => formatDateTime(r.scheduled_at) },
    { key: 'created_at', label: 'Created', render: (r) => formatDateTime(r.created_at) },
  ];

  const chips = [
    runId && { key: 'run', text: `Optimiser run #${runId}`, clear: () => setRunId('') },
    blockType && { key: 'type', text: `Block ${blockType}`, clear: () => setBlockType('') },
    contentId && { key: 'content', text: `Content item #${contentId}`, clear: () => setContentId('') },
  ].filter(Boolean);
  const filtered = Boolean(status || chips.length);

  const open = (r) => {
    const to = contentHref(r) || decisionHref(r);
    if (to) navigate(to);
  };

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Work blocks</h1>
        <p className="page-header__subtitle">
          AI work units the blog pipeline schedules (briefs, drafts, covers…). Open a row for the content it works on.
        </p>
      </div>

      <div className="filter-bar mb-4">
        <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          {status && !STATUSES.includes(status) && <option value={status}>{label(status)}</option>}
        </select>
      </div>

      {chips.length > 0 && (
        <div className="mb-4" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {chips.map((c) => (
            <button key={c.key} type="button" className="badge badge-secondary" onClick={c.clear} aria-label={`Clear ${c.text} filter`}>
              {c.text} ✕
            </button>
          ))}
        </div>
      )}

      {state.error && <Alert variant="danger">{state.error}</Alert>}
      {state.loading ? <Loader /> : (
        <div className="card">
          <DataTable
            columns={columns}
            rows={state.items}
            onRowClick={open}
            rowLabel={(r) => `Open work block ${r.id}`}
            emptyMessage={filtered ? 'No work blocks match this filter.' : 'No work blocks yet.'}
          />
        </div>
      )}
      <p className="text-xs text-muted">{state.total} work block{state.total === 1 ? '' : 's'}</p>
      <Pagination page={page} limit={LIMIT} total={state.total} onPage={setPage} />
    </div>
  );
}

export default BlogWorkBlocksPage;
