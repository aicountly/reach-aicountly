import { useState, useEffect } from 'react';
import api from '../../services/api';

const FINDING_CLASS = {
  spam: 'badge--error',
  prompt_injection: 'badge--error',
  legal_risk: 'badge--error',
  pii_detected: 'badge--warning',
  malicious_html: 'badge--error',
  unsafe_link: 'badge--warning',
  ungrounded_claim: 'badge--warning',
};

export default function CommunityModerationQueuePage() {
  const [findings, setFindings]   = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [page, setPage]           = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [actionMsg, setActionMsg] = useState('');

  function load() {
    setLoading(true);
    api.get('/community/moderation/queue', { params: { page } })
      .then(r => {
        setFindings(r.data?.data ?? []);
        setTotalPages(r.data?.meta?.last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [page]); // eslint-disable-line

  async function handleResolve(id) {
    const note = prompt('Resolution note:') || '';
    try {
      await api.post(`/community/moderation/${id}/resolve`, { resolution_note: note });
      setActionMsg(`Finding #${id} resolved.`);
      load();
    } catch (e) {
      setActionMsg('Error: ' + e.message);
    }
  }

  async function handleEscalate(id) {
    const note = prompt('Escalation note:') || '';
    try {
      await api.post(`/community/moderation/${id}/escalate`, { note });
      setActionMsg(`Finding #${id} escalated.`);
      load();
    } catch (e) {
      setActionMsg('Error: ' + e.message);
    }
  }

  if (loading) return <p className="muted">Loading moderation queue…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Moderation Queue</h1>
        <span className="badge badge--warning">{findings.length} open</span>
      </div>

      {actionMsg && <div className="alert alert--info mb-3">{actionMsg}</div>}

      <table className="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Finding type</th>
            <th>Severity</th>
            <th>Answer version</th>
            <th>Detail</th>
            <th>Flagged</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {findings.length === 0 ? (
            <tr><td colSpan={7} className="muted">Queue is empty.</td></tr>
          ) : findings.map(f => (
            <tr key={f.id}>
              <td>{f.id}</td>
              <td>
                <span className={`badge ${FINDING_CLASS[f.finding_type] ?? 'badge--neutral'}`}>{f.finding_type}</span>
              </td>
              <td>{f.severity ?? '—'}</td>
              <td>{f.answer_version_id ?? '—'} {f.version_number ? `(v${f.version_number})` : ''}</td>
              <td className="td--truncate">{f.detail ?? '—'}</td>
              <td>{f.created_at ? new Date(f.created_at).toLocaleDateString() : '—'}</td>
              <td>
                <button className="btn btn--sm btn--success mr-1" onClick={() => handleResolve(f.id)}>Resolve</button>
                <button className="btn btn--sm btn--ghost" onClick={() => handleEscalate(f.id)}>Escalate</button>
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
