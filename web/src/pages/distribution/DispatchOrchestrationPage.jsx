import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

export default function DispatchOrchestrationPage() {
  const [dispatches, setDispatches] = useState([]);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);
  const [page, setPage]             = useState(1);
  const [total, setTotal]           = useState(0);
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    api.get(`/distribution/dispatches?page=${page}&per_page=${perPage}`)
      .then(r => {
        setDispatches(r.data?.data?.data ?? r.data?.data ?? []);
        setTotal(r.data?.data?.total ?? 0);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  const handleReconcile = async (dispatchId) => {
    try {
      await api.post(`/distribution/dispatches/${dispatchId}/reconcile`);
      alert('Reconciliation job enqueued.');
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  const STATUS_COLORS = {
    pending:             'badge--neutral',
    processing:          'badge--warning',
    completed:           'badge--success',
    partially_completed: 'badge--warning',
    failed:              'badge--danger',
    dead_lettered:       'badge--danger',
  };

  return (
    <div>
      <div className="page-header">
        <h1>Dispatch Orchestration</h1>
        <p className="page-header__subtitle">Monitor and reconcile multi-channel campaign dispatches</p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading dispatches…</p>}

      {!loading && dispatches.length === 0 && (
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
            {dispatches.map(d => (
              <tr key={d.id}>
                <td>{d.campaign_id}</td>
                <td><span className="badge badge--neutral">{d.channel}</span></td>
                <td><span className={`badge ${STATUS_COLORS[d.status] ?? 'badge--neutral'}`}>{d.status}</span></td>
                <td>{d.total_recipients ?? '—'}</td>
                <td>{d.delivered_count ?? 0}</td>
                <td>{d.failed_count ?? 0}</td>
                <td>
                  {['processing', 'partially_completed'].includes(d.status) && (
                    <button className="btn btn--sm btn--outline" onClick={() => handleReconcile(d.id)}>
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
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="btn btn--sm">Previous</button>
          <span className="mx-2">Page {page} of {Math.ceil(total / perPage)}</span>
          <button disabled={page * perPage >= total} onClick={() => setPage(p => p + 1)} className="btn btn--sm">Next</button>
        </div>
      )}
    </div>
  );
}
