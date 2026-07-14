import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../services/api';

const STATUS_ACTIONS = {
  draft:            ['generate', 'save', 'run_moderation'],
  generated:        ['save', 'run_moderation', 'approve'],
  pending_approval: ['approve', 'reject'],
  approved:         ['publish'],
  published:        ['withdraw', 'correct'],
  withdrawn:        ['restore'],
};

export default function OfficialAnswerEditorPage() {
  const { uuid } = useParams();
  const [answer, setAnswer]   = useState(null);
  const [versions, setVersions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [body, setBody]       = useState('');
  const [saving, setSaving]   = useState(false);
  const [actionMsg, setActionMsg] = useState('');
  const [approvalNote, setApprovalNote] = useState('');
  const [withdrawReason, setWithdrawReason] = useState('');
  const [correctionNote, setCorrectionNote] = useState('');

  function load() {
    setLoading(true);
    Promise.all([
      api.get(`/community/answers/${uuid}`),
      api.get(`/community/answers/${uuid}/versions`),
    ])
      .then(([ar, vr]) => {
        const a = ar.data?.data;
        setAnswer(a);
        setBody(a?.current_body ?? '');
        setVersions(vr.data?.data ?? []);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [uuid]); // eslint-disable-line

  async function handleAction(action) {
    setActionMsg('');
    try {
      if (action === 'save') {
        setSaving(true);
        await api.put(`/community/answers/${uuid}`, { content: { body } });
        setActionMsg('Saved.');
      } else if (action === 'generate') {
        await api.post(`/community/answers/${uuid}/generate`, {});
        setActionMsg('AI generation triggered. Refresh to see result.');
      } else if (action === 'run_moderation') {
        await api.post(`/community/answers/${uuid}/run-moderation`, {});
        setActionMsg('Moderation run complete.');
      } else if (action === 'approve') {
        await api.post(`/community/answers/${uuid}/approve`, { note: approvalNote });
        setActionMsg('Approved.');
      } else if (action === 'reject') {
        const reason = prompt('Rejection reason:') || '';
        await api.post(`/community/answers/${uuid}/reject`, { reason });
        setActionMsg('Rejected.');
      } else if (action === 'publish') {
        await api.post(`/community/answers/${uuid}/publish`, {});
        setActionMsg('Published.');
      } else if (action === 'withdraw') {
        await api.post(`/community/answers/${uuid}/withdraw`, { reason: withdrawReason });
        setActionMsg('Withdrawn.');
      } else if (action === 'restore') {
        await api.post(`/community/answers/${uuid}/restore`, {});
        setActionMsg('Restored.');
      } else if (action === 'correct') {
        await api.post(`/community/answers/${uuid}/correct`, { content: { body }, correction_note: correctionNote });
        setActionMsg('Correction saved.');
      }
      await load();
    } catch (e) {
      setActionMsg('Error: ' + (e.response?.data?.error ?? e.message));
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <p className="muted">Loading answer…</p>;
  if (error) return <p className="text-error">{error}</p>;
  if (!answer) return <p className="text-error">Answer not found.</p>;

  const availableActions = STATUS_ACTIONS[answer.status] ?? [];

  return (
    <div>
      <div className="page-header">
        <Link to="/community/answers" className="btn btn--sm btn--ghost mb-2">← Back to answers</Link>
        <h1>Official Answer Editor</h1>
        <span className="badge badge--neutral mr-2">{answer.status}</span>
        {answer.risk_classification && (
          <span className={`badge ${answer.risk_classification === 'high' || answer.risk_classification === 'critical' ? 'badge--error' : 'badge--warning'}`}>
            Risk: {answer.risk_classification}
          </span>
        )}
      </div>

      {actionMsg && <div className="alert alert--info mb-3">{actionMsg}</div>}

      <div className="two-col-grid">
        <section>
          <div className="card mb-3">
            <h2 className="card__title">Answer content</h2>
            <textarea
              className="form-textarea"
              rows={16}
              value={body}
              onChange={e => setBody(e.target.value)}
              disabled={!availableActions.includes('save') && !availableActions.includes('correct')}
            />
            {availableActions.includes('correct') && (
              <input
                type="text"
                className="form-input mt-2"
                placeholder="Correction note (required for corrections)…"
                value={correctionNote}
                onChange={e => setCorrectionNote(e.target.value)}
              />
            )}
          </div>

          <div className="card">
            <h2 className="card__title">Actions</h2>
            <div className="btn-group">
              {availableActions.includes('generate') && (
                <button className="btn btn--sm" onClick={() => handleAction('generate')}>Generate with AI</button>
              )}
              {availableActions.includes('save') && (
                <button className="btn btn--sm btn--primary" onClick={() => handleAction('save')} disabled={saving}>
                  {saving ? 'Saving…' : 'Save draft'}
                </button>
              )}
              {availableActions.includes('correct') && (
                <button className="btn btn--sm btn--primary" onClick={() => handleAction('correct')} disabled={!correctionNote}>
                  Save correction
                </button>
              )}
              {availableActions.includes('run_moderation') && (
                <button className="btn btn--sm btn--ghost" onClick={() => handleAction('run_moderation')}>Run moderation</button>
              )}
              {availableActions.includes('approve') && (
                <>
                  <input
                    type="text"
                    className="form-input form-input--sm"
                    placeholder="Approval note…"
                    value={approvalNote}
                    onChange={e => setApprovalNote(e.target.value)}
                  />
                  <button className="btn btn--sm btn--success" onClick={() => handleAction('approve')}>Approve</button>
                </>
              )}
              {availableActions.includes('reject') && (
                <button className="btn btn--sm btn--ghost" onClick={() => handleAction('reject')}>Reject</button>
              )}
              {availableActions.includes('publish') && (
                <button className="btn btn--sm btn--success" onClick={() => handleAction('publish')}>Publish</button>
              )}
              {availableActions.includes('withdraw') && (
                <>
                  <input
                    type="text"
                    className="form-input form-input--sm"
                    placeholder="Withdrawal reason…"
                    value={withdrawReason}
                    onChange={e => setWithdrawReason(e.target.value)}
                  />
                  <button className="btn btn--sm btn--danger" onClick={() => handleAction('withdraw')}>Withdraw</button>
                </>
              )}
              {availableActions.includes('restore') && (
                <button className="btn btn--sm btn--success" onClick={() => handleAction('restore')}>Restore</button>
              )}
            </div>
          </div>
        </section>

        <section>
          <div className="card mb-3">
            <h2 className="card__title">Metadata</h2>
            <dl className="meta-list">
              <dt>UUID</dt><dd><code>{answer.external_id}</code></dd>
              <dt>Question</dt><dd>
                {answer.question_uuid
                  ? <Link to={`/community/questions/${answer.question_uuid}`}>{answer.question_uuid}</Link>
                  : '—'}
              </dd>
              <dt>Identity</dt><dd>{answer.official_identity_slug ?? '—'}</dd>
              <dt>AI assisted</dt><dd>{answer.ai_assisted ? 'Yes' : 'No'}</dd>
              <dt>Human reviewed</dt><dd>{answer.human_reviewed ? 'Yes' : 'No'}</dd>
              <dt>Checksum</dt><dd><code className="text-xs">{answer.current_checksum ?? '—'}</code></dd>
              <dt>Published at</dt><dd>{answer.published_at ?? '—'}</dd>
            </dl>
          </div>

          <div className="card">
            <h2 className="card__title">Version history ({versions.length})</h2>
            <table className="data-table data-table--compact">
              <thead>
                <tr><th>v</th><th>Source</th><th>Created</th></tr>
              </thead>
              <tbody>
                {versions.map(v => (
                  <tr key={v.id}>
                    <td>{v.version_number}</td>
                    <td>{v.generation_source}</td>
                    <td>{v.created_at ? new Date(v.created_at).toLocaleDateString() : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  );
}
