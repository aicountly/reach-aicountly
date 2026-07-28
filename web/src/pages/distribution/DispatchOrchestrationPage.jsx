import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.items)) return payload.items;
  return [];
}

const STATUS_COLORS = {
  queued: 'badge--neutral',
  dispatching: 'badge--warning',
  paused: 'badge--neutral',
  cancelled: 'badge--neutral',
  completed: 'badge--success',
  partially_completed: 'badge--warning',
  failed: 'badge--danger',
  dead_lettered: 'badge--danger',
};

export default function DispatchOrchestrationPage() {
  const [dispatches, setDispatches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/distribution/dispatches', { page, per_page: perPage })
      .then((r) => {
        setDispatches(normalizeList(r));
        setTotal(Number(r?.total ?? 0));
      })
      .catch((e) => {
        if (e?.status === 404) {
          setDispatches([]);
          setTotal(0);
          setError(null);
          return;
        }
        setError(e?.message || 'Failed to load dispatches.');
      })
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  const handleReconcile = async (dispatchId) => {
    try {
      await api.post(`v1/distribution/dispatches/${dispatchId}/reconcile`);
      alert('Reconciliation job enqueued.');
      load();
    } catch (e) {
      alert(e?.message || 'Reconciliation failed.');
    }
  };

  const totalPages = Math.max(1, Math.ceil(total / perPage));

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Dispatch Orchestration</h1>
        <p className="page-header__subtitle">
          Monitor and reconcile multi-channel campaign dispatches
        </p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading dispatches…</p>}

      {!loading && dispatches.length === 0 && !error && (
        <p className="muted">No dispatches found.</p>
      )}

      {!loading && dispatches.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Campaign</th>
              <th>Channel</th>
              <th>Status</th>
              <th>Recipients</th>
              <th>Delivered</th>
              <th>Failed</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {dispatches.map((d) => (
              <tr key={d.id}>
                <td>{d.campaign_id}</td>
                <td><span className="badge badge--neutral">{d.channel}</span></td>
                <td>
                  <span className={`badge ${STATUS_COLORS[d.status] ?? 'badge--neutral'}`}>
                    {d.status}
                  </span>
                </td>
                <td>{d.total_recipients ?? '—'}</td>
                <td>{d.delivered_count ?? d.sent_count ?? 0}</td>
                <td>{d.failed_count ?? 0}</td>
                <td>
                  {['dispatching', 'partially_completed'].includes(d.status) && (
                    <button
                      type="button"
                      className="btn btn--sm btn--secondary"
                      onClick={() => handleReconcile(d.id)}
                    >
                      Reconcile
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {total > perPage && (
        <div className="pagination mt-4">
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
            className="btn btn--sm btn--secondary"
          >
            Previous
          </button>
          <span className="pagination__info">Page {page} of {totalPages}</span>
          <button
            type="button"
            disabled={page >= totalPages}
            onClick={() => setPage((p) => p + 1)}
            className="btn btn--sm btn--secondary"
          >
            Next
          </button>
        </div>
      )}
    </div>
  );
}
