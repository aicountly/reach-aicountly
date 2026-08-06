import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { formatDate } from '../../utils/formatDate';

export default function ConsentListPage() {
  const [consents, setConsents] = useState([]);
  const [total, setTotal]       = useState(0);
  const [page, setPage]         = useState(1);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/distribution/consents', { page, per_page: perPage })
      .then((r) => {
        const rows = Array.isArray(r) ? r : (Array.isArray(r?.data) ? r.data : (Array.isArray(r?.items) ? r.items : []));
        setConsents(rows);
        setTotal(Number(r?.total ?? 0));
      })
      .catch((e) => {
        if (e?.status === 404 || e?.status === 500) {
          setConsents([]);
          setTotal(0);
          setError(null);
          return;
        }
        setError(e?.message || 'Failed to load consents.');
      })
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  const handleRevoke = async (id) => {
    if (!confirm('Revoke this consent record?')) return;
    try {
      await api.delete(`v1/distribution/consents/${id}`);
      load();
    } catch (e) {
      alert(e.message);
    }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <Link to="/distribution/audience" className="breadcrumb-link">Audience</Link>
          {' / '}
          <h1 style={{ display: 'inline' }}>Channel Consents</h1>
          <p className="page-header__subtitle">
            Granted and revoked consent records used for channel eligibility checks
          </p>
        </div>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading consents…</p>}

      {!loading && consents.length === 0 && (
        <p className="muted">No consent records yet.</p>
      )}

      {!loading && consents.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Channel</th>
              <th>Subject</th>
              <th>Purpose</th>
              <th>Status</th>
              <th>Source</th>
              <th>Captured</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {consents.map(c => (
              <tr key={c.id}>
                <td><span className="badge badge--neutral">{c.channel}</span></td>
                <td>{c.subject_type} #{c.subject_id}</td>
                <td>{c.purpose}</td>
                <td>
                  <span className={`badge ${c.status === 'granted' ? 'badge--success' : 'badge--neutral'}`}>
                    {c.status}
                  </span>
                </td>
                <td>{c.source ?? '—'}</td>
                <td>{c.captured_at ? formatDate(c.captured_at) : '—'}</td>
                <td>
                  {c.status === 'granted' && (
                    <button className="btn btn--sm btn--error" onClick={() => handleRevoke(c.id)}>
                      Revoke
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
