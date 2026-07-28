import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

export default function CampaignListPage() {
  const [campaigns, setCampaigns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [status, setStatus] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const params = {};
    if (status) params.status = status;

    api.get('v1/campaigns', params)
      .then((r) => setCampaigns(normalizeList(r)))
      .catch((e) => setError(e?.message || 'Failed to load campaigns.'))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Campaign Workspace</h1>
          <p className="muted">Versioned campaigns with approval workflow for omnichannel distribution.</p>
        </div>
      </div>

      <div className="filter-bar">
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          {['draft', 'pending_approval', 'approved', 'scheduled', 'running', 'completed', 'paused', 'archived'].map((s) => (
            <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
          ))}
        </select>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading campaigns…</p>}

      {!loading && campaigns.length === 0 && (
        <p className="muted">No campaigns found.</p>
      )}

      {!loading && campaigns.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Status</th>
              <th>Updated</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {campaigns.map((c) => (
              <tr key={c.id}>
                <td>{c.name ?? `Campaign #${c.id}`}</td>
                <td>{c.campaign_type ?? c.type ?? '—'}</td>
                <td>
                  <span className="badge badge--neutral">
                    {(c.status ?? 'unknown').replace(/_/g, ' ')}
                  </span>
                </td>
                <td>{c.updated_at ? new Date(c.updated_at).toLocaleDateString() : '—'}</td>
                <td>
                  <Link
                    to={`/distribution/campaigns/${c.id}`}
                    className="btn btn--sm btn--secondary"
                  >
                    Open workspace
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
