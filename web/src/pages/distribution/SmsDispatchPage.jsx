import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

export default function SmsDispatchPage() {
  const [campaigns, setCampaigns] = useState([]);
  const [capabilities, setCaps]   = useState(null);
  const [total, setTotal]         = useState(0);
  const [page, setPage]           = useState(1);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [filter, setFilter]       = useState('approved');
  const perPage = 25;

  const loadCampaigns = useCallback(() => {
    setLoading(true);
    api.get(`/campaigns?channel=sms&status=${filter}&page=${page}&per_page=${perPage}`)
      .then(r => {
        setCampaigns(r.data?.data?.data ?? r.data?.data ?? []);
        setTotal(r.data?.data?.total ?? 0);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, filter]);

  useEffect(() => { loadCampaigns(); }, [loadCampaigns]);

  useEffect(() => {
    api.get('/distribution/sms/capabilities')
      .then(r => setCaps(r.data?.data))
      .catch(() => {});
  }, []);

  const handleDispatch = async (id) => {
    if (!confirm('Dispatch this SMS campaign? Ensure DLT registration is valid.')) return;
    try {
      const r = await api.post(`/distribution/sms/dispatch/${id}`);
      alert(`Dispatch result: ${r.data?.data?.status ?? 'dispatched'}`);
      loadCampaigns();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  const FILTERS = ['all', 'approved', 'completed', 'failed', 'scheduled'];

  return (
    <div>
      <div className="page-header">
        <h1>SMS Distribution</h1>
        <p className="page-header__subtitle">Dispatch approved SMS campaigns via provider with DLT compliance</p>
      </div>

      {capabilities && (
        <div className="info-box mb-4">
          <strong>Provider: {capabilities.provider_name}</strong>
          {capabilities.dlt_required && (
            <span className="badge badge--warning ml-2">DLT Required</span>
          )}
          <span className="ml-2">Max length: {capabilities.max_body_chars ?? 160} chars</span>
        </div>
      )}

      <div className="filter-bar mb-4">
        {FILTERS.map(f => (
          <button
            key={f}
            className={`btn btn--sm mr-1 ${filter === f ? 'btn--primary' : 'btn--outline'}`}
            onClick={() => { setFilter(f); setPage(1); }}
          >
            {f.charAt(0).toUpperCase() + f.slice(1)}
          </button>
        ))}
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading SMS campaigns…</p>}

      {!loading && campaigns.length === 0 && (
        <p className="muted">No SMS campaigns found for the selected filter.</p>
      )}

      {!loading && campaigns.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Campaign</th>
              <th>DLT Entity</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {campaigns.map(c => (
              <tr key={c.id}>
                <td>{c.name ?? c.title ?? `Campaign #${c.id}`}</td>
                <td>{c.dlt_entity_id ?? '—'}</td>
                <td><span className="badge badge--neutral">{c.status}</span></td>
                <td>
                  {c.status === 'approved' && (
                    <button className="btn btn--sm btn--primary" onClick={() => handleDispatch(c.id)}>
                      Dispatch
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
