import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { normalizeCommunityList, normalizeCommunityObject } from './communityListUtils';
import { formatDateTime } from '../../utils/formatDate';

// The status moves a person makes by hand, keyed by the current status, as
// App\Enums\CommunityQuestionStatus::validTransitions() allows them. The
// system makes the rest: creating an answer advances the question to
// draft_requested, and merging a duplicate sets duplicate_merged. The old
// in_progress/closed/spam options are not statuses, so the API rejected them.
const MANUAL_TRANSITIONS = {
  intake:              ['triaged', 'archived'],
  triaged:             ['archived'],
  draft_requested:     ['archived'],
  validation_failed:   ['archived'],
  moderation_required: ['archived'],
  verification_failed: ['archived'],
  withdrawn:           ['archived'],
};

export default function QuestionWorkspacePage() {
  const { uuid } = useParams();
  const navigate  = useNavigate();
  const [question, setQuestion]   = useState(null);
  const [answers, setAnswers]     = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [transitioning, setTransitioning] = useState(false);
  const [generating, setGenerating]       = useState(false);
  const [statusNote, setStatusNote]       = useState('');
  const [newStatus, setNewStatus]         = useState('');

  function load() {
    setLoading(true);
    Promise.all([
      api.get(`v1/community/questions/${uuid}`),
      api.get('v1/community/answers', { question_uuid: uuid }),
    ])
      .then(([qr, ar]) => {
        const q = normalizeCommunityObject(qr);
        setQuestion(Object.keys(q).length ? q : (qr ?? null));
        setAnswers(normalizeCommunityList(ar));
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [uuid]); // eslint-disable-line

  function handleStatusChange(e) {
    e.preventDefault();
    if (!newStatus) return;
    setTransitioning(true);
    api.put(`v1/community/questions/${uuid}/status`, { status: newStatus, note: statusNote })
      .then(() => {
        setNewStatus('');
        setStatusNote('');
        load();
      })
      .catch(e => alert(e.message))
      .finally(() => setTransitioning(false));
  }

  function handleGenerateAnswer() {
    setGenerating(true);
    api.post('v1/community/answers', { question_uuid: uuid })
      .then(r => {
        const answerUuid = r?.uuid;
        if (answerUuid) navigate(`/community/answers/${answerUuid}`);
      })
      .catch(e => alert(e.message))
      .finally(() => setGenerating(false));
  }

  if (loading) return <p className="muted">Loading question…</p>;
  if (error) return <p className="text-error">{error}</p>;
  if (!question) return <p className="text-error">Question not found.</p>;

  const transitions = MANUAL_TRANSITIONS[question.status] ?? [];

  return (
    <div>
      <div className="page-header">
        <Link to="/community/questions" className="btn btn--sm btn--ghost mb-2">← Back to inbox</Link>
        <h1>{question.title}</h1>
        <span className="badge badge--neutral">{question.status}</span>
      </div>

      <div className="two-col-grid">
        <section className="card">
          <h2 className="card__title">Question details</h2>
          {/* Field names are QuestionController::show's own; it returns the
              question as the inbox lists it (space and current risk joined). */}
          <dl className="meta-list">
            <dt>UUID</dt><dd><code>{question.uuid}</code></dd>
            <dt>Space</dt><dd>{question.space_slug ?? '—'}</dd>
            <dt>Source</dt><dd>{question.source_type ?? '—'}</dd>
            <dt>Risk</dt><dd>{question.risk_classification ?? '—'}</dd>
            <dt>Triage score</dt><dd>{question.triage_score ?? '—'}</dd>
            <dt>Received</dt><dd>{formatDateTime(question.intake_timestamp)}</dd>
          </dl>
          <div className="mt-3">
            <p className="label">Body</p>
            <div className="prose-box">{question.body || '—'}</div>
          </div>
        </section>

        <section>
          <div className="card mb-3">
            <h2 className="card__title">Change status</h2>
            {transitions.length === 0 ? (
              <p className="muted">No manual status change from {question.status}.</p>
            ) : (
              <form onSubmit={handleStatusChange} className="inline-form">
                <select value={newStatus} onChange={e => setNewStatus(e.target.value)} className="form-select form-select--sm">
                  <option value="">Select…</option>
                  {transitions.map(s => <option key={s} value={s}>{s}</option>)}
                </select>
                <input
                  type="text"
                  value={statusNote}
                  onChange={e => setStatusNote(e.target.value)}
                  placeholder="Note (optional)"
                  className="form-input form-input--sm"
                />
                <button className="btn btn--sm" disabled={!newStatus || transitioning}>
                  {transitioning ? 'Saving…' : 'Update'}
                </button>
              </form>
            )}
          </div>

          <div className="card">
            <h2 className="card__title">Official answers ({answers.length})</h2>
            {answers.length === 0 ? (
              <p className="muted mb-2">No official answer yet.</p>
            ) : answers.map(a => (
              <div key={a.id} className="list-item">
                <span className="badge badge--neutral">{a.status}</span>
                <Link to={`/community/answers/${a.uuid}`} className="ml-2">{a.uuid}</Link>
              </div>
            ))}
            {/* A question holds one official answer; the API refuses a second. */}
            {answers.length === 0 && (
              <button className="btn btn--sm mt-2" onClick={handleGenerateAnswer} disabled={generating}>
                {generating ? 'Creating…' : '+ Create official answer'}
              </button>
            )}
          </div>
        </section>
      </div>
    </div>
  );
}
