import React, { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { listGenerations } from '../../services/aiService.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';

export default function AiGenerationsPage() {
  const [requests, setRequests] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const perPage = 20;
  const totalPages = Math.max(1, Math.ceil(total / perPage));

  const load = useCallback(() => {
    setLoading(true);
    listGenerations(page)
      .then(d => { setRequests(d.requests || []); setTotal(d.total || 0); })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Generation Requests</h1>
        <p className="page-header__subtitle">{total} total</p>
      </div>

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
                <tr key={r.uuid}>
                  <td>
                    <Link to={`/ai/generations/${r.uuid}`} className="text-sm">
                      {r.uuid?.slice(0, 8)}…
                    </Link>
                  </td>
                  <td>{r.task_type}</td>
                  <td>{r.content_type}</td>
                  <td><AiGenerationBadge status={r.status} /></td>
                  <td className="text-muted text-xs">{r.requested_actor_type}</td>
                  <td className="text-muted text-xs">{r.created_at?.slice(0, 10)}</td>
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
