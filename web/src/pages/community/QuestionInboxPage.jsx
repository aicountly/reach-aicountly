import { useState, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { normalizeCommunityList, normalizeCommunityMeta } from './communityListUtils';
import { formatDate, formatDateTime } from '../../utils/formatDate';

// Values mirror App\Enums\CommunityQuestionStatus, limited to the ones a
// question reaches today: published mirrors a live answer, the rest stop at
// draft_requested while the answer moves through vetting.
const STATUS_OPTS = [
  { value: '', label: 'All' },
  { value: 'intake', label: 'Intake' },
  { value: 'triaged', label: 'Triaged' },
  { value: 'draft_requested', label: 'Draft requested' },
  { value: 'published', label: 'Published' },
  { value: 'archived', label: 'Archived' },
  { value: 'duplicate_merged', label: 'Duplicate merged' },
];

// Who handles the question next — App\Libraries\Community\Automation\
// CommunityInboxPolicy::HANDLERS. Nothing here waits on a person.
const HANDLERS = {
  routine_queue:   { label: 'Routine queue', badge: 'badge--info' },
  routine_vetting: { label: 'Vetting the routine’s answer', badge: 'badge--info' },
  publishing:      { label: 'Publishing', badge: 'badge--info' },
  published:       { label: 'Published', badge: 'badge--success' },
  auto_closed:     { label: 'Auto-closed', badge: 'badge--neutral' },
  closed:          { label: 'Closed', badge: 'badge--neutral' },
};
const HANDLER_OPTS = [
  { value: '', label: 'Anyone' },
  ...Object.entries(HANDLERS).map(([value, h]) => ({ value, label: h.label })),
];

const SORT_OPTS = [
  { value: 'triage_score_desc', label: 'Triage score' },
  { value: 'newest', label: 'Newest' },
  { value: 'oldest', label: 'Oldest' },
];

const STATUS_CLASS = {
  intake: 'badge--info',
  triaged: 'badge--info',
  draft_requested: 'badge--warning',
  published: 'badge--success',
  archived: 'badge--neutral',
  duplicate_merged: 'badge--neutral',
};

/** When the current handler took over: closed, queued, or the last change. */
function handlerSince(q) {
  if (q.handled_by === 'auto_closed' || q.handled_by === 'closed') return q.closed_at ?? q.handling_updated_at;
  if (q.handled_by === 'routine_queue') return q.routine_queued_at ?? q.handling_updated_at;
  return q.handling_updated_at;
}

function HandledBy({ q }) {
  const h = HANDLERS[q.handled_by];
  if (!h) return <span className="muted">Not yet assigned (next sweep)</span>;
  const link = q.handled_by === 'published' ? q.answer_public_url : q.answered_by_public_url;
  const since = handlerSince(q);
  return (
    <div>
      <span className={`badge ${h.badge}`}>{h.label}</span>
      {since && <div className="muted text-sm">since {formatDateTime(since)}</div>}
      {q.handling_note && <div className="text-sm">{q.handling_note}</div>}
      {link && (
        <a href={link} target="_blank" rel="noreferrer" className="text-sm">
          {q.handled_by === 'published' ? 'View answer' : 'View the existing answer'}
        </a>
      )}
    </div>
  );
}

export default function QuestionInboxPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const { user } = useAuth() ?? {};
  // Manual tools (status changes, a hand-made answer) stay available to super
  // admins; nobody else is expected to act on a row.
  const isSuperAdmin = user?.role === 'super_admin';
  // The filters live in the URL so the Overview tiles can link to them.
  const status = searchParams.get('status') ?? '';
  const handledBy = searchParams.get('handled_by') ?? '';
  const [questions, setQuestions] = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [sort, setSort]           = useState('triage_score_desc');
  const [page, setPage]           = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  function changeFilter(key, value) {
    const next = { status, handled_by: handledBy, [key]: value };
    setSearchParams(Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { replace: true });
    setPage(1);
  }

  function load() {
    setLoading(true);
    api.getPage('v1/community/questions', {
      status: status || undefined,
      handled_by: handledBy || undefined,
      sort,
      page,
    })
      .then(r => {
        setQuestions(normalizeCommunityList(r));
        setTotalPages(normalizeCommunityMeta(r).last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [status, handledBy, sort, page]); // eslint-disable-line

  return (
    <div>
      <div className="page-header">
        <h1>Question Inbox</h1>
      </div>
      <p className="muted mb-3">
        Nothing here needs a person. Every few minutes Reach marks answered questions published,
        closes duplicates and questions the desks will not answer (with the reason), and hands
        the rest to the reach-community-qa routine, whose answers are vetted and published automatically.
      </p>

      <div className="toolbar mb-3">
        <label className="toolbar__label">
          Status
          <select value={status} onChange={e => changeFilter('status', e.target.value)} className="form-select form-select--sm">
            {STATUS_OPTS.map(s => <option key={s.value || 'all'} value={s.value}>{s.label}</option>)}
          </select>
        </label>
        <label className="toolbar__label">
          Handled by
          <select value={handledBy} onChange={e => changeFilter('handled_by', e.target.value)} className="form-select form-select--sm">
            {HANDLER_OPTS.map(s => <option key={s.value || 'any'} value={s.value}>{s.label}</option>)}
          </select>
        </label>
        <label className="toolbar__label">
          Sort
          <select value={sort} onChange={e => { setSort(e.target.value); setPage(1); }} className="form-select form-select--sm">
            {SORT_OPTS.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
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
              <th>Handled by</th>
            </tr>
          </thead>
          <tbody>
            {questions.length === 0 ? (
              <tr><td colSpan={7} className="muted">No questions.</td></tr>
            ) : questions.map(q => (
              <tr key={q.id}>
                {/* Field names are the API row's own (QuestionController::index):
                    uuid is the public id the workspace route resolves, and
                    intake_timestamp is when Reach received the question. */}
                <td className="td--primary">
                  {isSuperAdmin
                    ? <Link to={`/community/questions/${q.uuid}`} title="Manual tools (super admin)">{q.title}</Link>
                    : q.title}
                </td>
                <td>{q.space_slug ?? '—'}</td>
                <td>
                  <span className={`badge ${STATUS_CLASS[q.status] ?? 'badge--neutral'}`}>{q.status}</span>
                </td>
                <td>{q.risk_classification ?? '—'}</td>
                <td>{q.triage_score ?? '—'}</td>
                <td>{formatDate(q.intake_timestamp)}</td>
                <td><HandledBy q={q} /></td>
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
