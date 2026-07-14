import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

const STATUS_OPTS = ['', 'draft', 'generated', 'pending_approval', 'approved', 'published', 'withdrawn'];
const STATUS_CLASS = {
  draft: 'badge--neutral',
  generated: 'badge--info',
  pending_approval: 'badge--warning',
  approved: 'badge--info',
  published: 'badge--success',
  withdrawn: 'badge--error',
};

export default function OfficialAnswerListPage() {
  const [answers, setAnswers]     = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [status, setStatus]       = useState('');
  const [page, setPage]           = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  useEffect(() => {
    setLoading(true);
    api.get('/community/answers', { params: { status: status || undefined, page } })
      .then(r => {
        setAnswers(r.data?.data ?? []);
        setTotalPages(r.data?.meta?.last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [status, page]);

  return (
    <div>
      <div className="page-header">
        <h1>Official Answers</h1>
      </div>

      <div className="toolbar mb-3">
        <label className="toolbar__label">
          Status
          <select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} className="form-select form-select--sm">
            {STATUS_OPTS.map(s => <option key={s} value={s}>{s || 'All'}</option>)}
          </select>
        </label>
      </div>

      {loading && <p className="muted">Loading…</p>}
      {error && <p className="text-error">{error}</p>}

      {!loading && !error && (
        <table className="data-table">
          <thead>
            <tr>
              <th>UUID</th>
              <th>Status</th>
              <th>Risk</th>
              <th>AI assisted</th>
              <th>Human reviewed</th>
              <th>Updated</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {answers.length === 0 ? (
              <tr><td colSpan={7} className="muted">No answers.</td></tr>
            ) : answers.map(a => (
              <tr key={a.id}>
                <td><code className="text-xs">{a.external_id?.slice(0, 12)}…</code></td>
                <td><span className={`badge ${STATUS_CLASS[a.status] ?? 'badge--neutral'}`}>{a.status}</span></td>
                <td>{a.risk_classification ?? '—'}</td>
                <td>{a.ai_assisted ? 'Yes' : 'No'}</td>
                <td>{a.human_reviewed ? 'Yes' : 'No'}</td>
                <td>{a.updated_at ? new Date(a.updated_at).toLocaleDateString() : '—'}</td>
                <td>
                  <Link to={`/community/answers/${a.external_id}`} className="btn btn--sm">Edit</Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

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
