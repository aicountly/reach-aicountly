import React, { useState, useEffect } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { listAiUsage } from '../../services/aiService.js';
import { FilterBar } from '../../components/common/FilterBar';
import { useUrlFilter, drillHref } from '../../components/drill';
import { formatDate } from '../../utils/formatDate';

const PER_PAGE = 50;

// Narrowing params with no control of their own (the dashboard's "Today's
// cost" and the budget bars link with these) — shown as removable chips.
const CHIP_FILTERS = {
  date:          (v) => (v === 'today' ? 'Today' : `On ${v}`),
  billing_month: (v) => (v === 'current' ? 'This month' : `Month ${v}`),
};

export default function AiUsagePage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [provider, setProvider] = useUrlFilter('provider');
  const [model, setModel] = useUrlFilter('model');
  const [taskType, setTaskType] = useUrlFilter('task_type');
  const [contentType, setContentType] = useUrlFilter('content_type');
  const [from, setFrom] = useUrlFilter('from');
  const [to, setTo] = useUrlFilter('to');
  const [usage, setUsage] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const chips = Object.keys(CHIP_FILTERS)
    .map((k) => [k, searchParams.get(k) || ''])
    .filter(([, v]) => v);
  const date = searchParams.get('date') || '';
  const billingMonth = searchParams.get('billing_month') || '';

  // A new filter is a new list: start it from page 1.
  const filterKey = [provider, model, taskType, contentType, from, to, date, billingMonth].join('|');
  const [lastFilterKey, setLastFilterKey] = useState(filterKey);
  if (filterKey !== lastFilterKey) {
    setLastFilterKey(filterKey);
    setPage(1);
  }

  useEffect(() => {
    setLoading(true);
    setError(null);
    listAiUsage({
      page, provider, model, task_type: taskType, content_type: contentType,
      from, to, date, billing_month: billingMonth,
    })
      .then(d => { setUsage(d.usage || d.ledger || []); setTotal(d.total ?? 0); })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, provider, model, taskType, contentType, from, to, date, billingMonth]);

  const clearChip = (key) => setSearchParams((prev) => {
    const next = new URLSearchParams(prev);
    next.delete(key);
    return next;
  }, { replace: true });

  // In-page drill: keep the current filters and narrow by one more.
  const narrow = (patch) => drillHref(`/ai/usage?${searchParams}`, patch);
  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Usage Ledger</h1>
        <p className="page-header__subtitle">
          All AI generation costs are tracked per provider, model, user, and content type.
        </p>
      </div>

      <FilterBar>
        <input value={provider} onChange={(e) => setProvider(e.target.value)} placeholder="Provider" aria-label="Provider" />
        <input value={model} onChange={(e) => setModel(e.target.value)} placeholder="Model" aria-label="Model" />
        <input value={taskType} onChange={(e) => setTaskType(e.target.value)} placeholder="Task type" aria-label="Task type" />
        <input value={contentType} onChange={(e) => setContentType(e.target.value)} placeholder="Content type" aria-label="Content type" />
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="From date" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="To date" />
      </FilterBar>

      {chips.length > 0 && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {chips.map(([k, v]) => (
            <button key={k} type="button" className="badge badge-secondary" onClick={() => clearChip(k)}
                    aria-label={`Clear ${CHIP_FILTERS[k](v)} filter`}>
              {CHIP_FILTERS[k](v)} ✕
            </button>
          ))}
          <span className="text-xs text-muted">{total} matching record{total === 1 ? '' : 's'}</span>
        </div>
      )}

      {error && <p className="text-error">Error: {error}</p>}
      {loading && <p className="muted">Loading usage data…</p>}

      {!loading && !error && (usage.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>No usage records found.</p>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                {['Date', 'Provider', 'Model', 'Task', 'Content Type', 'Input Tokens', 'Output Tokens', 'Cost', 'Currency'].map(h => (
                  <th key={h}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {usage.map((u, i) => {
                // A ledger row opens the generation it paid for, when it has one.
                const detail = u.generation_uuid ? `/ai/generations/${u.generation_uuid}` : null;
                const stop = (e) => e.stopPropagation();
                return (
                  <tr
                    key={u.id || i}
                    className={detail ? 'is-drillable' : undefined}
                    tabIndex={detail ? 0 : undefined}
                    onClick={detail ? () => navigate(detail) : undefined}
                    onKeyDown={detail ? (e) => { if (e.key === 'Enter' && e.target === e.currentTarget) navigate(detail); } : undefined}
                  >
                    <td className="text-muted">{formatDate(u.usage_date)}</td>
                    <td>
                      {u.provider_key
                        ? <Link to={narrow({ provider: u.provider_key })} className="drill-link" onClick={stop}>{u.provider_key}</Link>
                        : '—'}
                    </td>
                    <td>
                      {u.model_key
                        ? <Link to={narrow({ model: u.model_key })} className="drill-link" onClick={stop}><code className="text-xs">{u.model_key}</code></Link>
                        : '—'}
                    </td>
                    <td>
                      {u.task_type
                        ? (
                          <Link to={drillHref('/ai/generations', { task_type: u.task_type })} className="drill-link" onClick={stop}
                                title="Generation requests for this task">
                            {u.task_type}
                          </Link>
                        )
                        : '—'}
                    </td>
                    <td className="text-muted">{u.content_type}</td>
                    <td className="text-muted">{u.input_tokens?.toLocaleString()}</td>
                    <td className="text-muted">{u.output_tokens?.toLocaleString()}</td>
                    <td className="font-semibold">{parseFloat(u.estimated_cost || 0).toFixed(6)}</td>
                    <td className="text-muted">{u.currency || 'USD'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ))}

      {!loading && total > PER_PAGE && (
        <div className="pagination">
          <button type="button" className="btn btn--sm btn--secondary" onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1}>
            Prev
          </button>
          <span className="pagination__info">Page {page} / {totalPages}</span>
          <button type="button" className="btn btn--sm btn--secondary" onClick={() => setPage(p => p + 1)} disabled={page >= totalPages}>
            Next
          </button>
        </div>
      )}
    </div>
  );
}
