import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

const STATUS_OPTS = ['', 'new', 'triaged', 'in_progress', 'answered', 'closed', 'spam'];
const SORT_OPTS   = ['triage_score_desc', 'newest', 'oldest'];

const STATUS_CLASS = {
  new: 'badge--info',
  triaged: 'badge--info',
  in_progress: 'badge--warning',
  answered: 'badge--success',
  closed: 'badge--neutral',
  spam: 'badge--error',
};

export default function QuestionInboxPage() {
  const [questions, setQuestions] = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [status, setStatus]       = useState('');
  const [sort, setSort]           = useState('triage_score_desc');
  const [page, setPage]           = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  function load() {
    setLoading(true);
    api.get('/community/questions', { params: { status: status || undefined, sort, page } })
      .then(r => {
        setQuestions(r.data?.data ?? []);
        setTotalPages(r.data?.meta?.last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [status, sort, page]); // eslint-disable-line

  return (
    <div>
      <div className="page-header">
        <h1>Question Inbox</h1>
      </div>

      <div className="toolbar mb-3">
        <label className="toolbar__label">
          Status
          <select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} className="form-select form-select--sm">
            {STATUS_OPTS.map(s => <option key={s} value={s}>{s || 'All'}</option>)}
          </select>
        </label>
        <label className="toolbar__label">
          Sort
          <select value={sort} onChange={e => setSort(e.target.value)} className="form-select form-select--sm">
            {SORT_OPTS.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
      </div>

      {loading && <p className="muted">Loading…</p>}
      {error && <p className="text-error">{error}</p>}

      {!loading && !error && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Question</th>
              <th>Space</th>
              <th>Status</th>
              <th>Risk</th>
              <th>Triage score</th>
              <th>Received</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {questions.length === 0 ? (
              <tr><td colSpan={7} className="muted">No questions.</td></tr>
            ) : questions.map(q => (
              <tr key={q.id}>
                <td className="td--primary">{q.title}</td>
                <td>{q.space_slug ?? '—'}</td>
                <td>
                  <span className={`badge ${STATUS_CLASS[q.status] ?? 'badge--neutral'}`}>{q.status}</span>
                </td>
                <td>{q.risk_classification ?? '—'}</td>
                <td>{q.triage_score ?? '—'}</td>
                <td>{q.source_received_at ? new Date(q.source_received_at).toLocaleDateString() : '—'}</td>
                <td>
                  <Link to={`/community/questions/${q.external_id}`} className="btn btn--sm">Open</Link>
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
