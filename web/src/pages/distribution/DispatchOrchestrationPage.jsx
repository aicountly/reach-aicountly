import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { DrillLink, useUrlFilter } from '../../components/drill';

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

const STATUSES = Object.keys(STATUS_COLORS);
const CHANNELS = ['email', 'sms', 'whatsapp', 'social'];

export default function DispatchOrchestrationPage() {
  const [dispatches, setDispatches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  // Filters live in the URL: the dashboard's dispatch-queue tile links here
  // with ?status=queued, and a filtered view must survive reload / Back.
  const [status, setStatusParam] = useUrlFilter('status');
  const [channel, setChannelParam] = useUrlFilter('channel');
  const navigate = useNavigate();
  const perPage = 25;

  const setStatus = (v) => { setStatusParam(v); setPage(1); };
  const setChannel = (v) => { setChannelParam(v); setPage(1); };

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const params = { page, per_page: perPage };
    if (status) params.status = status;
    if (channel) params.channel = channel;
    api.get('v1/distribution/dispatches', params)
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
  }, [page, status, channel]);

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

      <div className="filter-bar mb-4">
        <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          {status && !STATUSES.includes(status) && <option value={status}>{status}</option>}
        </select>
        <select value={channel} onChange={(e) => setChannel(e.target.value)} aria-label="Channel">
          <option value="">All channels</option>
          {CHANNELS.map((c) => <option key={c} value={c}>{c}</option>)}
          {channel && !CHANNELS.includes(channel) && <option value={channel}>{channel}</option>}
        </select>
        {(status || channel) && (
          <span className="muted text-sm">{total} matching dispatch{total === 1 ? '' : 'es'}</span>
        )}
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading dispatches…</p>}

      {!loading && dispatches.length === 0 && !error && (
        <p className="muted">No dispatches found{status || channel ? ' for this filter' : ''}.</p>
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
              <tr
                key={d.id}
                className={d.campaign_id ? 'is-drillable' : undefined}
                tabIndex={d.campaign_id ? 0 : undefined}
                aria-label={d.campaign_id ? `Open campaign ${d.campaign_id}` : undefined}
                onClick={d.campaign_id ? () => navigate(`/distribution/campaigns/${d.campaign_id}`) : undefined}
                onKeyDown={d.campaign_id ? (e) => {
                  if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) {
                    e.preventDefault();
                    navigate(`/distribution/campaigns/${d.campaign_id}`);
                  }
                } : undefined}
              >
                <td>
                  <DrillLink to={d.campaign_id ? `/distribution/campaigns/${d.campaign_id}` : undefined}>
                    {d.campaign_id ?? '—'}
                  </DrillLink>
                </td>
                <td>
                  <DrillLink
                    onClick={d.channel && d.channel !== channel ? () => setChannel(d.channel) : undefined}
                    title="Only this channel"
                  >
                    <span className="badge badge--neutral">{d.channel}</span>
                  </DrillLink>
                </td>
                <td>
                  <DrillLink
                    onClick={d.status && d.status !== status ? () => setStatus(d.status) : undefined}
                    title="Only this status"
                  >
                    <span className={`badge ${STATUS_COLORS[d.status] ?? 'badge--neutral'}`}>
                      {d.status}
                    </span>
                  </DrillLink>
                </td>
                <td>{d.total_recipients ?? '—'}</td>
                <td>{d.delivered_count ?? d.sent_count ?? 0}</td>
                <td>{d.failed_count ?? 0}</td>
                <td>
                  {['dispatching', 'partially_completed'].includes(d.status) && (
                    <button
                      type="button"
                      className="btn btn--sm btn--secondary"
                      onClick={(e) => { e.stopPropagation(); handleReconcile(d.id); }}
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
