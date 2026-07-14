import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

const SUPPRESSION_REASONS = ['unsubscribe', 'bounce', 'complaint', 'manual', 'legal', 'opt_out', 'invalid_address'];

export default function SuppressionPage() {
  const [suppressions, setSuppressions] = useState([]);
  const [total, setTotal]               = useState(0);
  const [page, setPage]                 = useState(1);
  const [loading, setLoading]           = useState(true);
  const [error, setError]               = useState(null);
  const [adding, setAdding]             = useState(false);
  const [form, setForm]                 = useState({ channel: 'email', address: '', reason: 'manual' });
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    api.get(`/distribution/suppressions?page=${page}&per_page=${perPage}`)
      .then(r => {
        setSuppressions(r.data?.data?.data ?? []);
        setTotal(r.data?.data?.total ?? 0);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  const handleAdd = async () => {
    if (!form.address.trim()) { alert('Address is required'); return; }
    try {
      await api.post('/distribution/suppressions', form);
      setAdding(false);
      setForm({ channel: 'email', address: '', reason: 'manual' });
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  const handleRemove = async (id) => {
    if (!confirm('Remove this suppression?')) return;
    try {
      await api.delete(`/distribution/suppressions/${id}`);
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  return (
    <div>
      <div className="page-header">
        <h1>Suppression List</h1>
        <p className="page-header__subtitle">Manage unsubscribes, bounces, and manual suppressions</p>
        <div className="page-header__actions">
          <button className="btn btn--primary" onClick={() => setAdding(s => !s)}>+ Add suppression</button>
        </div>
      </div>

      {adding && (
        <div className="card mb-4">
          <h3 className="card__title">Add suppression</h3>
          <div className="form-row mb-2">
            <select className="form-select mr-2" value={form.channel} onChange={e => setForm(f => ({...f, channel: e.target.value}))}>
              <option value="email">Email</option>
              <option value="sms">SMS</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="social">Social</option>
            </select>
            <input className="form-input mr-2" placeholder="Address" value={form.address} onChange={e => setForm(f => ({...f, address: e.target.value}))} />
            <select className="form-select" value={form.reason} onChange={e => setForm(f => ({...f, reason: e.target.value}))}>
              {SUPPRESSION_REASONS.map(r => <option key={r} value={r}>{r}</option>)}
            </select>
          </div>
          <button className="btn btn--primary" onClick={handleAdd}>Add</button>
        </div>
      )}

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading suppressions…</p>}

      {!loading && suppressions.length === 0 && (
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
            {suppressions.map(s => (
              <tr key={s.id}>
                <td><span className="badge badge--neutral">{s.channel}</span></td>
                <td>{s.address_masked ?? '***'}</td>
                <td>{s.reason}</td>
                <td>{s.suppressed_at ? new Date(s.suppressed_at).toLocaleDateString() : '—'}</td>
                <td>
                  <button className="btn btn--sm btn--error" onClick={() => handleRemove(s.id)}>Remove</button>
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
