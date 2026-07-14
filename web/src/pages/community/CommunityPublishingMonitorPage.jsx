import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

const STATUS_CLASS = {
  pending: 'badge--info',
  sent: 'badge--info',
  confirmed: 'badge--success',
  verified: 'badge--success',
  failed: 'badge--error',
  retrying: 'badge--warning',
};

export default function CommunityPublishingMonitorPage() {
  const [deployments, setDeployments] = useState([]);
  const [loading, setLoading]         = useState(true);
  const [error, setError]             = useState(null);
  const [page, setPage]               = useState(1);
  const [totalPages, setTotalPages]   = useState(1);
  const [actionMsg, setActionMsg]     = useState('');

  function load() {
    setLoading(true);
    api.get('/community/deployments', { params: { page } })
      .then(r => {
        setDeployments(r.data?.data ?? []);
        setTotalPages(r.data?.meta?.last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [page]); // eslint-disable-line

  async function handleRetry(uuid) {
    try {
      await api.post(`/community/deployments/${uuid}/retry`, {});
      setActionMsg(`Deployment ${uuid} retried.`);
      load();
    } catch (e) {
      setActionMsg('Error: ' + e.message);
    }
  }

  async function handleVerify(uuid) {
    try {
      await api.post(`/community/deployments/${uuid}/verify`, {});
      setActionMsg(`Verification triggered for ${uuid}.`);
      load();
    } catch (e) {
      setActionMsg('Error: ' + e.message);
    }
  }

  if (loading) return <p className="muted">Loading deployments…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Publishing Monitor</h1>
      </div>

      {actionMsg && <div className="alert alert--info mb-3">{actionMsg}</div>}

      <table className="data-table">
        <thead>
          <tr>
            <th>UUID</th>
            <th>Answer</th>
            <th>Operation</th>
            <th>Status</th>
            <th>Attempts</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {deployments.length === 0 ? (
            <tr><td colSpan={7} className="muted">No deployments.</td></tr>
          ) : deployments.map(d => (
            <tr key={d.id}>
              <td><code className="text-xs">{d.external_id?.slice(0, 8)}…</code></td>
              <td>
                {d.answer_uuid
                  ? <Link to={`/community/answers/${d.answer_uuid}`}>{d.answer_uuid.slice(0, 8)}…</Link>
                  : '—'}
              </td>
              <td><code>{d.operation}</code></td>
              <td>
                <span className={`badge ${STATUS_CLASS[d.status] ?? 'badge--neutral'}`}>{d.status}</span>
              </td>
              <td>{d.attempt_count ?? 0}</td>
              <td>{d.updated_at ? new Date(d.updated_at).toLocaleDateString() : '—'}</td>
              <td>
                {d.status === 'failed' && (
                  <button className="btn btn--sm mr-1" onClick={() => handleRetry(d.external_id)}>Retry</button>
                )}
                {d.status === 'confirmed' && (
                  <button className="btn btn--sm btn--ghost" onClick={() => handleVerify(d.external_id)}>Verify</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {totalPages > 1 && (
        <div className="pagination">
          <button className="btn btn--sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Prev</button>
          <span className="pagination__info">Page {page} / {totalPages}</span>
          <button className="btn btn--sm" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>Next</button>
        </div>
      )}
    </div>
  );
}
