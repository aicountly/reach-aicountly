import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { normalizeCommunityList, normalizeCommunityMeta } from './communityListUtils';

// Mirrors App\Enums\CommunityQuestionStatus. The previous list (new /
// in_progress / answered / closed / spam) belonged to no state machine in the
// backend, so every filter returned nothing and every badge fell back to grey.
const STATUS_OPTS = [
  { value: '', label: 'All' },
  { value: 'intake', label: 'Intake' },
  { value: 'triaged', label: 'Triaged' },
  { value: 'draft_requested', label: 'Draft requested' },
  { value: 'generating', label: 'Generating' },
  { value: 'draft_generated', label: 'Draft generated' },
  { value: 'validation_failed', label: 'Validation failed' },
  { value: 'moderation_required', label: 'Moderation required' },
  { value: 'editorial_review', label: 'Editorial review' },
  { value: 'professional_review', label: 'Professional review' },
  { value: 'changes_requested', label: 'Changes requested' },
  { value: 'approved', label: 'Approved' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'publishing', label: 'Publishing' },
  { value: 'published', label: 'Published' },
  { value: 'verification_failed', label: 'Verification failed' },
  { value: 'unpublished', label: 'Unpublished' },
  { value: 'withdrawn', label: 'Withdrawn' },
  { value: 'archived', label: 'Archived' },
];
const SORT_OPTS = [
  { value: 'triage_score_desc', label: 'Triage score' },
  { value: 'newest', label: 'Newest' },
  { value: 'oldest', label: 'Oldest' },
];

const STATUS_CLASS = {
  intake: 'badge--info',
  triaged: 'badge--info',
  draft_requested: 'badge--info',
  generating: 'badge--warning',
  draft_generated: 'badge--warning',
  validation_failed: 'badge--error',
  moderation_required: 'badge--error',
  editorial_review: 'badge--warning',
  professional_review: 'badge--warning',
  changes_requested: 'badge--warning',
  approved: 'badge--success',
  scheduled: 'badge--success',
  publishing: 'badge--warning',
  published: 'badge--success',
  verification_failed: 'badge--error',
  unpublished: 'badge--neutral',
  withdrawn: 'badge--neutral',
  archived: 'badge--neutral',
};

const receivedAt = q => q.intake_timestamp ?? q.question_timestamp ?? null;

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
    api.get('v1/community/questions', { status: status || undefined, sort, page })
      .then(r => {
        setQuestions(normalizeCommunityList(r));
        setTotalPages(normalizeCommunityMeta(r).last_page ?? 1);
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
            {STATUS_OPTS.map(s => <option key={s.value || 'all'} value={s.value}>{s.label}</option>)}
          </select>
        </label>
        <label className="toolbar__label">
          Sort
          <select value={sort} onChange={e => setSort(e.target.value)} className="form-select form-select--sm">
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
              <th>Live</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {questions.length === 0 ? (
              <tr><td colSpan={8} className="muted">No questions.</td></tr>
            ) : questions.map(q => (
              <tr key={q.id}>
                <td className="td--primary">{q.title}</td>
                <td>{q.space_slug ?? '—'}</td>
                <td>
                  <span className={`badge ${STATUS_CLASS[q.status] ?? 'badge--neutral'}`}>{q.status}</span>
                </td>
                <td>{q.risk_classification ?? '—'}</td>
                <td>{q.triage_score ?? '—'}</td>
                <td>{receivedAt(q) ? new Date(receivedAt(q)).toLocaleDateString() : '—'}</td>
                <td>
                  {(q.public_url ?? q.answer_public_url)
                    ? (
                      <a
                        href={q.public_url ?? q.answer_public_url}
                        target="_blank"
                        rel="noreferrer"
                      >
                        View on aicountly.com
                      </a>
                    )
                    : '—'}
                </td>
                <td>
                  {/* The API row's public identifier is `uuid`; `external_id`
                      is kept as a fallback for older payload shapes. */}
                  <Link to={`/community/questions/${q.uuid ?? q.external_id}`} className="btn btn--sm">Open</Link>
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
