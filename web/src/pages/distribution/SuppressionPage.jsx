import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';
import { formatDate } from '../../utils/formatDate';

const SUPPRESSION_REASONS = ['unsubscribe', 'bounce', 'complaint', 'manual', 'legal', 'opt_out', 'invalid_address'];

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.items)) return payload.items;
  return [];
}

export default function SuppressionPage() {
  const [suppressions, setSuppressions] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState({ channel: 'email', address: '', reason: 'manual' });
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/distribution/suppressions', { page, per_page: perPage })
      .then((r) => {
        setSuppressions(normalizeList(r));
        setTotal(Number(r?.total ?? 0));
      })
      .catch((e) => {
        // Soft-fail list: show empty state instead of a hard crash for missing/unavailable endpoint.
        if (e?.status === 404) {
          setSuppressions([]);
          setTotal(0);
          setError(null);
          return;
        }
        setError(e?.message || 'Failed to load suppressions.');
      })
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  const handleAdd = async () => {
    if (!form.address.trim()) {
      alert('Address is required');
      return;
    }
    try {
      await api.post('v1/distribution/suppressions', form);
      setAdding(false);
      setForm({ channel: 'email', address: '', reason: 'manual' });
      load();
    } catch (e) {
      alert(e?.message || 'Failed to add suppression.');
    }
  };

  const handleRemove = async (id) => {
    if (!confirm('Remove this suppression?')) return;
    try {
      await api.delete(`v1/distribution/suppressions/${id}`);
      load();
    } catch (e) {
      alert(e?.message || 'Failed to remove suppression.');
    }
  };

  const totalPages = Math.max(1, Math.ceil(total / perPage));

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Suppression List</h1>
          <p className="muted">Manage unsubscribes, bounces, and manual suppressions</p>
        </div>
        <button className="btn btn--primary" onClick={() => setAdding((s) => !s)}>
          + Add suppression
        </button>
      </div>

      {adding && (
        <div className="card mb-4">
          <div className="card__header">
            <h3 className="card__title">Add suppression</h3>
          </div>
          <div className="card__body">
            <div className="filter-bar" style={{ marginBottom: '0.75rem' }}>
              <select
                value={form.channel}
                onChange={(e) => setForm((f) => ({ ...f, channel: e.target.value }))}
              >
                <option value="email">Email</option>
                <option value="sms">SMS</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="social">Social</option>
              </select>
              <input
                placeholder="Address"
                value={form.address}
                onChange={(e) => setForm((f) => ({ ...f, address: e.target.value }))}
              />
              <select
                value={form.reason}
                onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))}
              >
                {SUPPRESSION_REASONS.map((r) => (
                  <option key={r} value={r}>{r}</option>
                ))}
              </select>
            </div>
            <button className="btn btn--primary" onClick={handleAdd}>Add</button>
          </div>
        </div>
      )}

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading suppressions…</p>}

      {!loading && suppressions.length === 0 && !error && (
        <p className="muted">No suppressions. Unsubscribes and bounces will appear here automatically.</p>
      )}

      {!loading && suppressions.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Channel</th>
              <th>Address (masked)</th>
              <th>Reason</th>
              <th>Suppressed at</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {suppressions.map((s) => (
              <tr key={s.id}>
                <td><span className="badge badge--neutral">{s.channel}</span></td>
                <td>{s.address_masked ?? '***'}</td>
                <td>{s.reason}</td>
                <td>{s.suppressed_at ? formatDate(s.suppressed_at) : '—'}</td>
                <td>
                  <button className="btn btn--sm btn--error" onClick={() => handleRemove(s.id)}>
                    Remove
                  </button>
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
