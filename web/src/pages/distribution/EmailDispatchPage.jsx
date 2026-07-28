import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

export default function EmailDispatchPage() {
  const [campaigns, setCampaigns] = useState([]);
  const [total, setTotal]         = useState(0);
  const [page, setPage]           = useState(1);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [filter, setFilter]       = useState('approved');
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/email/campaigns', {
      status: filter === 'all' ? undefined : filter,
      page,
      limit: perPage,
    })
      .then(r => {
        const items = Array.isArray(r) ? r : (r?.items ?? r?.data ?? []);
        setCampaigns(items);
        setTotal(r?.total ?? items.length);
      })
      .catch(e => {
        setError(e.message);
        setCampaigns([]);
        setTotal(0);
      })
      .finally(() => setLoading(false));
  }, [page, filter]);

  useEffect(() => { load(); }, [load]);

  const handleDispatch = async (id) => {
    if (!confirm('Dispatch this email campaign to the provider?')) return;
    try {
      const r = await api.post(`v1/distribution/email/dispatch/${id}`);
      alert(`Dispatch result: ${r?.status ?? 'dispatched'}`);
      load();
    } catch (e) {
      alert(e.message);
    }
  };

  const FILTERS = ['all', 'approved', 'sent', 'failed', 'scheduled', 'draft'];

  const renderStats = (raw) => {
    if (!raw) return '—';
    try {
      const s = typeof raw === 'string' ? JSON.parse(raw) : raw;
      return `Opens: ${s.opens ?? 0} | Clicks: ${s.clicks ?? 0} | Bounces: ${s.bounces ?? 0}`;
    } catch { return '—'; }
  };

  return (
    <div>
      <div className="page-header">
        <h1>Email Distribution</h1>
        <p className="page-header__subtitle">Dispatch approved email campaigns via provider</p>
      </div>

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
      {loading && <p className="muted">Loading email campaigns…</p>}

      {!loading && campaigns.length === 0 && (
        <p className="muted">No email campaigns found for the selected filter.</p>
      )}

      {!loading && campaigns.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Subject</th>
              <th>From</th>
              <th>Status</th>
              <th>Stats</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {campaigns.map(c => (
              <tr key={c.id}>
                <td>{c.subject}</td>
                <td>{c.from_name ?? c.from_email ?? '—'}</td>
                <td><span className="badge badge--neutral">{c.status}</span></td>
                <td className="text-sm">{renderStats(c.stats)}</td>
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
