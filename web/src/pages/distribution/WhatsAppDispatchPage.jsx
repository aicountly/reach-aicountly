import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

export default function WhatsAppDispatchPage() {
  const [campaigns, setCampaigns] = useState([]);
  const [templates, setTemplates] = useState([]);
  const [total, setTotal]         = useState(0);
  const [page, setPage]           = useState(1);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [filter, setFilter]       = useState('approved');
  const perPage = 25;

  const loadCampaigns = useCallback(() => {
    setLoading(true);
    api.get(`/whatsapp-campaigns?status=${filter}&page=${page}&per_page=${perPage}`)
      .then(r => {
        setCampaigns(r.data?.data?.data ?? r.data?.data ?? []);
        setTotal(r.data?.data?.total ?? 0);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, filter]);

  useEffect(() => { loadCampaigns(); }, [loadCampaigns]);

  useEffect(() => {
    api.get('/distribution/whatsapp/templates')
      .then(r => setTemplates(r.data?.data ?? []))
      .catch(() => {});
  }, []);

  const handleDispatch = async (id) => {
    if (!confirm('Dispatch this WhatsApp campaign? All recipients must be opted-in.')) return;
    try {
      const r = await api.post(`/distribution/whatsapp/dispatch/${id}`);
      alert(`Dispatch result: ${r.data?.data?.status ?? 'dispatched'}`);
      loadCampaigns();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  const FILTERS = ['all', 'approved', 'sent', 'failed', 'scheduled', 'draft'];

  return (
    <div>
      <div className="page-header">
        <h1>WhatsApp Distribution</h1>
        <p className="page-header__subtitle">
          Dispatch approved WhatsApp campaigns via provider. All recipients must be opted-in.
        </p>
      </div>

      {templates.length > 0 && (
        <div className="info-box mb-4">
          <strong>Template Catalogue ({templates.length})</strong>
          <ul className="template-list">
            {templates.map(t => (
              <li key={t.id}>{t.name} — <span className="badge badge--neutral">{t.status}</span></li>
            ))}
          </ul>
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
      {loading && <p className="muted">Loading WhatsApp campaigns…</p>}

      {!loading && campaigns.length === 0 && (
        <p className="muted">No WhatsApp campaigns found for the selected filter.</p>
      )}

      {!loading && campaigns.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Template</th>
              <th>Status</th>
              <th>Recipients</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {campaigns.map(c => (
              <tr key={c.id}>
                <td>{c.template_id ?? '—'}</td>
                <td><span className="badge badge--neutral">{c.status}</span></td>
                <td>{c.recipient_count ?? '—'}</td>
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
