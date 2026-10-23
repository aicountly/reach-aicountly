import React, { useState, useEffect, useCallback } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { listGenerations } from '../../services/aiService.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';
import { FilterBar } from '../../components/common/FilterBar';
import { useUrlFilter, drillHref } from '../../components/drill';
import { formatDate } from '../../utils/formatDate';

const STATUSES = ['pending', 'grounding', 'queued', 'processing', 'validating', 'completed', 'failed', 'cancelled', 'blocked'];

// Params the dashboard and usage ledger drill in with that have no control
// of their own here — shown as removable chips so a short list explains itself.
const CHIP_FILTERS = {
  error_category: (v) => `Error: ${v}`,
  created_on:     (v) => `Created ${v}`,
  completed_on:   (v) => `Completed ${v}`,
  from:           (v) => `From ${v}`,
  to:             (v) => `To ${v}`,
};

export default function AiGenerationsPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [status, setStatus] = useUrlFilter('status');
  const [taskType, setTaskType] = useUrlFilter('task_type');
  const [contentType, setContentType] = useUrlFilter('content_type');
  const [requests, setRequests] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const perPage = 20;
  const totalPages = Math.max(1, Math.ceil(total / perPage));

  const chips = Object.keys(CHIP_FILTERS)
    .map((k) => [k, searchParams.get(k) || ''])
    .filter(([, v]) => v);
  const chipKey = chips.map(([k, v]) => `${k}=${v}`).join('&');

  // A new filter is a new list: start it from page 1.
  const filterKey = `${status}|${taskType}|${contentType}|${chipKey}`;
  const [lastFilterKey, setLastFilterKey] = useState(filterKey);
  if (filterKey !== lastFilterKey) {
    setLastFilterKey(filterKey);
    setPage(1);
  }

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const extra = Object.fromEntries(new URLSearchParams(chipKey));
    listGenerations(page, { status, task_type: taskType, content_type: contentType, ...extra })
      .then(d => { setRequests(d.requests || []); setTotal(d.total || 0); })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, status, taskType, contentType, chipKey]);

  useEffect(() => { load(); }, [load]);

  const clearChip = (key) => setSearchParams((prev) => {
    const next = new URLSearchParams(prev);
    next.delete(key);
    return next;
  }, { replace: true });

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Generation Requests</h1>
        <p className="page-header__subtitle">{total} total</p>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          {status && !STATUSES.includes(status) && <option value={status}>{status}</option>}
        </select>
        <input
          value={taskType}
          onChange={(e) => setTaskType(e.target.value)}
          placeholder="Task type"
          aria-label="Task type"
        />
        <input
          value={contentType}
          onChange={(e) => setContentType(e.target.value)}
          placeholder="Content type"
          aria-label="Content type"
        />
      </FilterBar>

      {chips.length > 0 && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {chips.map(([k, v]) => (
            <button key={k} type="button" className="badge badge-secondary" onClick={() => clearChip(k)}
                    aria-label={`Clear ${CHIP_FILTERS[k](v)} filter`}>
              {CHIP_FILTERS[k](v)} ✕
            </button>
          ))}
        </div>
      )}

      {error && <p className="text-error mb-3">Error: {error}</p>}
      {loading && <p className="muted">Loading…</p>}

      {!loading && requests.length === 0 && (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              No generation requests found.
            </p>
          </div>
        </div>
      )}

      {!loading && requests.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                {['UUID', 'Task', 'Content Type', 'Status', 'Requested By', 'Created'].map(h => (
                  <th key={h}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {requests.map(r => (
                <tr
                  key={r.uuid}
                  className="is-drillable"
                  tabIndex={0}
                  onClick={() => navigate(`/ai/generations/${r.uuid}`)}
                  onKeyDown={(e) => { if (e.key === 'Enter' && e.target === e.currentTarget) navigate(`/ai/generations/${r.uuid}`); }}
                >
                  <td>
                    <Link to={`/ai/generations/${r.uuid}`} className="text-sm" onClick={(e) => e.stopPropagation()}>
                      {r.uuid?.slice(0, 8)}…
                    </Link>
                  </td>
                  <td>
                    <Link
                      to={drillHref(`/ai/generations?${searchParams}`, { task_type: r.task_type })}
                      className="drill-link"
                      onClick={(e) => { e.stopPropagation(); }}
                      title="Only this task type"
                    >
                      {r.task_type}
                    </Link>
                  </td>
                  <td>{r.content_type}</td>
                  <td><AiGenerationBadge status={r.status} /></td>
                  <td className="text-muted text-xs">{r.requested_actor_type}</td>
                  <td className="text-muted text-xs">{formatDate(r.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && total > 0 && (
        <div className="pagination">
          <button
            type="button"
            className="btn btn--sm btn--secondary"
            onClick={() => setPage(p => Math.max(1, p - 1))}
            disabled={page === 1}
          >
            Prev
          </button>
          <span className="pagination__info">Page {page} / {totalPages}</span>
          <button
            type="button"
            className="btn btn--sm btn--secondary"
            onClick={() => setPage(p => p + 1)}
            disabled={page >= totalPages || requests.length < perPage}
          >
            Next
          </button>
        </div>
      )}
    </div>
  );
}
