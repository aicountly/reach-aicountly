import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import api from '../../services/api';

const TRANSITION_STATUSES = ['triaged', 'in_progress', 'closed', 'spam'];

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
      api.get(`/community/questions/${uuid}`),
      api.get('/community/answers', { params: { question_uuid: uuid } }),
    ])
      .then(([qr, ar]) => {
        setQuestion(qr.data?.data);
        setAnswers(ar.data?.data ?? []);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [uuid]); // eslint-disable-line

  function handleStatusChange(e) {
    e.preventDefault();
    if (!newStatus) return;
    setTransitioning(true);
    api.put(`/community/questions/${uuid}/status`, { status: newStatus, note: statusNote })
      .then(() => load())
      .catch(e => alert(e.message))
      .finally(() => setTransitioning(false));
  }

  function handleGenerateAnswer() {
    setGenerating(true);
    api.post('/community/answers', { question_uuid: uuid })
      .then(r => {
        const answerUuid = r.data?.data?.external_id;
        if (answerUuid) navigate(`/community/answers/${answerUuid}`);
      })
      .catch(e => alert(e.message))
      .finally(() => setGenerating(false));
  }

  if (loading) return <p className="muted">Loading question…</p>;
  if (error) return <p className="text-error">{error}</p>;
  if (!question) return <p className="text-error">Question not found.</p>;

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
          <dl className="meta-list">
            <dt>UUID</dt><dd><code>{question.external_id}</code></dd>
            <dt>Space</dt><dd>{question.space_slug ?? '—'}</dd>
            <dt>Source</dt><dd>{question.source_platform ?? '—'}</dd>
            <dt>Risk</dt><dd>{question.risk_classification ?? '—'}</dd>
            <dt>Triage score</dt><dd>{question.triage_score ?? '—'}</dd>
            <dt>Received</dt><dd>{question.source_received_at ?? '—'}</dd>
          </dl>
          <div className="mt-3">
            <p className="label">Body</p>
            <div className="prose-box">{question.body ?? '—'}</div>
          </div>
        </section>

        <section>
          <div className="card mb-3">
            <h2 className="card__title">Change status</h2>
            <form onSubmit={handleStatusChange} className="inline-form">
              <select value={newStatus} onChange={e => setNewStatus(e.target.value)} className="form-select form-select--sm">
                <option value="">Select…</option>
                {TRANSITION_STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
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
          </div>

          <div className="card">
            <h2 className="card__title">Official answers ({answers.length})</h2>
            {answers.length === 0 ? (
              <p className="muted mb-2">No official answer yet.</p>
            ) : answers.map(a => (
              <div key={a.id} className="list-item">
                <span className="badge badge--neutral">{a.status}</span>
                <Link to={`/community/answers/${a.external_id}`} className="ml-2">{a.external_id}</Link>
              </div>
            ))}
            <button className="btn btn--sm mt-2" onClick={handleGenerateAnswer} disabled={generating}>
              {generating ? 'Creating…' : '+ Create official answer'}
            </button>
          </div>
        </section>
      </div>
    </div>
  );
}
