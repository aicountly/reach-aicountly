import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { DeleteButton } from '../../components/common/DeleteButton';
import { usePermission } from '../../hooks/usePermission';
import { normalizeCommunityList, normalizeCommunityObject } from './communityListUtils';

// Keys mirror App\Enums\CommunityAnswerStatus — the old map used invented
// statuses (draft/generated/pending_approval) that exist nowhere, so real
// answers (e.g. validation_failed) rendered with zero action buttons.
const STATUS_ACTIONS = {
  draft_requested:     ['generate'],
  generating:          [],
  draft_generated:     ['generate', 'save', 'validate', 'run_moderation', 'submit_review'],
  validation_failed:   ['generate', 'save', 'validate'],
  moderation_required: ['save', 'run_moderation'],
  editorial_review:    ['approve', 'reject'],
  professional_review: ['approve', 'reject'],
  changes_requested:   ['generate', 'save', 'validate'],
  approved:            ['publish'],
  scheduled:           ['publish'],
  published:           ['withdraw', 'correct'],
  verification_failed: ['publish'],
  withdrawn:           ['restore'],
};

const STATUS_HINT = {
  generating: 'AI generation is in progress — reload in a moment.',
  validation_failed:
    'Generation or validation failed. Regenerate with AI, or edit the content, save, and re-run Validate.',
  changes_requested: 'A reviewer requested changes. Edit and validate, or regenerate.',
  editorial_review: 'Awaiting editorial approval.',
  professional_review: 'High-risk answer — requires professional approval.',
  approved: 'Ready to publish to aicountly.com/community.',
};

export default function OfficialAnswerEditorPage() {
  const { uuid } = useParams();
  const navigate = useNavigate();
  const { has } = usePermission();
  const canDelete = has('community_answer.delete');
  const [answer, setAnswer]   = useState(null);
  const [versions, setVersions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [body, setBody]       = useState('');
  const [excerpt, setExcerpt] = useState('');
  const [busy, setBusy]       = useState(false);
  const [actionMsg, setActionMsg] = useState('');
  const [findings, setFindings]   = useState([]);
  const [approvalNote, setApprovalNote] = useState('');
  const [withdrawReason, setWithdrawReason] = useState('');
  const [correctionNote, setCorrectionNote] = useState('');

  function load() {
    setLoading(true);
    Promise.all([
      api.get(`v1/community/answers/${uuid}`),
      api.get(`v1/community/answers/${uuid}/versions`),
    ])
      .then(([ar, vr]) => {
        const a = normalizeCommunityObject(ar);
        const ans = Object.keys(a).length ? a : (ar ?? null);
        const vers = normalizeCommunityList(vr); // newest first
        setAnswer(ans);
        setVersions(vers);
        // Content lives on versions, not the answer aggregate.
        setBody(vers[0]?.content ?? '');
        setExcerpt(vers[0]?.excerpt ?? '');
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [uuid]); // eslint-disable-line

  async function handleAction(action) {
    setActionMsg('');
    setFindings([]);
    setBusy(true);
    try {
      if (action === 'save') {
        await api.put(`v1/community/answers/${uuid}`, { content: body, excerpt });
        setActionMsg('Saved as a new version.');
      } else if (action === 'generate') {
        setActionMsg('Generating draft with AI — this can take up to a minute…');
        await api.post(`v1/community/answers/${uuid}/generate`, {});
        setActionMsg('Draft generated.');
      } else if (action === 'validate') {
        const r = await api.post(`v1/community/answers/${uuid}/validate`, {});
        const d = r?.data ?? r;
        setFindings(d?.findings ?? []);
        setActionMsg(d?.passed ? 'Validation passed.' : 'Validation failed — see findings below.');
      } else if (action === 'submit_review') {
        await api.post(`v1/community/answers/${uuid}/submit-review`, {});
        setActionMsg('Submitted for review.');
      } else if (action === 'run_moderation') {
        await api.post(`v1/community/answers/${uuid}/run-moderation`, {});
        setActionMsg('Moderation run complete.');
      } else if (action === 'approve') {
        await api.post(`v1/community/answers/${uuid}/approve`, { note: approvalNote });
        setActionMsg('Approved.');
      } else if (action === 'reject') {
        const reason = prompt('Rejection reason:') || '';
        if (!reason) { setBusy(false); return; }
        await api.post(`v1/community/answers/${uuid}/reject`, { reason });
        setActionMsg('Rejected.');
      } else if (action === 'publish') {
        await api.post(`v1/community/answers/${uuid}/publish`, {});
        setActionMsg('Publish requested — deployment to aicountly.com is queued.');
      } else if (action === 'withdraw') {
        await api.post(`v1/community/answers/${uuid}/withdraw`, { reason: withdrawReason });
        setActionMsg('Withdrawn.');
      } else if (action === 'restore') {
        await api.post(`v1/community/answers/${uuid}/restore`, {});
        setActionMsg('Restored.');
      } else if (action === 'correct') {
        await api.post(`v1/community/answers/${uuid}/correct`, {
          content: body, excerpt, correction_note: correctionNote,
        });
        setActionMsg('Correction saved.');
      }
      await load();
    } catch (e) {
      setActionMsg('Error: ' + (e.response?.data?.error ?? e.message));
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <p className="muted">Loading answer…</p>;
  if (error) return <p className="text-error">{error}</p>;
  if (!answer) return <p className="text-error">Answer not found.</p>;

  const availableActions = STATUS_ACTIONS[answer.status] ?? [];
  const canEdit = availableActions.includes('save') || availableActions.includes('correct');
  const answerUuid = answer.uuid ?? answer.external_id ?? uuid;

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
        {canDelete && (
          <DeleteButton
            className="btn btn--sm btn--danger ml-2"
            confirmMessage={
              'Permanently delete this official answer?\n\n'
              + 'Its versions, approvals and deployment records go with it, and a '
              + 'published answer is removed from the public site. This cannot be undone.'
            }
            onDelete={(force) => api.delete(
              `v1/community/answers/${answerUuid}`,
              { reason: 'Deleted from the answer editor', force },
            )}
            onDeleted={() => navigate('/community/answers')}
            onError={(msg) => msg && setActionMsg(msg)}
          />
        )}
      </div>

      {STATUS_HINT[answer.status] && <div className="alert alert--info mb-3">{STATUS_HINT[answer.status]}</div>}
      {actionMsg && <div className="alert alert--info mb-3">{actionMsg}</div>}

      {findings.length > 0 && (
        <div className="card mb-3">
          <h2 className="card__title">Validation findings</h2>
          <ul>
            {findings.map((f, i) => (
              <li key={i}>
                <span className={`badge ${f.severity === 'error' || f.severity === 'critical' ? 'badge--error' : 'badge--warning'} mr-2`}>{f.severity}</span>
                {f.detail}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="two-col-grid">
        <section>
          <div className="card mb-3">
            <h2 className="card__title">Answer content</h2>
            {versions.length === 0 && (
              <p className="muted">No draft yet — use “Generate with AI” to create the first version.</p>
            )}
            <textarea
              className="form-textarea"
              rows={16}
              value={body}
              onChange={e => setBody(e.target.value)}
              disabled={!canEdit}
            />
            <input
              type="text"
              className="form-input mt-2"
              placeholder="Short answer / excerpt…"
              value={excerpt}
              onChange={e => setExcerpt(e.target.value)}
              disabled={!canEdit}
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
                <button className="btn btn--sm" onClick={() => handleAction('generate')} disabled={busy}>
                  {versions.length === 0 ? 'Generate with AI' : 'Regenerate with AI'}
                </button>
              )}
              {availableActions.includes('save') && (
                <button className="btn btn--sm btn--primary" onClick={() => handleAction('save')} disabled={busy}>
                  {busy ? 'Working…' : 'Save draft'}
                </button>
              )}
              {availableActions.includes('validate') && (
                <button className="btn btn--sm btn--ghost" onClick={() => handleAction('validate')} disabled={busy || versions.length === 0}>
                  Validate
                </button>
              )}
              {availableActions.includes('run_moderation') && (
                <button className="btn btn--sm btn--ghost" onClick={() => handleAction('run_moderation')} disabled={busy}>Run moderation</button>
              )}
              {availableActions.includes('submit_review') && (
                <button className="btn btn--sm btn--success" onClick={() => handleAction('submit_review')} disabled={busy}>Submit for review</button>
              )}
              {availableActions.includes('correct') && (
                <button className="btn btn--sm btn--primary" onClick={() => handleAction('correct')} disabled={busy || !correctionNote}>
                  Save correction
                </button>
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
                  <button className="btn btn--sm btn--success" onClick={() => handleAction('approve')} disabled={busy}>Approve</button>
                </>
              )}
              {availableActions.includes('reject') && (
                <button className="btn btn--sm btn--ghost" onClick={() => handleAction('reject')} disabled={busy}>Reject</button>
              )}
              {availableActions.includes('publish') && (
                <button className="btn btn--sm btn--success" onClick={() => handleAction('publish')} disabled={busy}>Publish</button>
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
                  <button className="btn btn--sm btn--danger" onClick={() => handleAction('withdraw')} disabled={busy || !withdrawReason}>Withdraw</button>
                </>
              )}
              {availableActions.includes('restore') && (
                <button className="btn btn--sm btn--success" onClick={() => handleAction('restore')} disabled={busy}>Restore</button>
              )}
            </div>
          </div>
        </section>

        <section>
          <div className="card mb-3">
            <h2 className="card__title">Metadata</h2>
            <dl className="meta-list">
              <dt>UUID</dt><dd><code>{answerUuid}</code></dd>
              <dt>Question</dt><dd>
                {answer.question_uuid
                  ? <Link to={`/community/questions/${answer.question_uuid}`}>{answer.question_title ?? answer.question_uuid}</Link>
                  : '—'}
              </dd>
              <dt>Identity</dt><dd>{answer.identity_name ?? answer.identity_slug ?? '—'}</dd>
              <dt>AI assisted</dt><dd>{answer.ai_assisted ? 'Yes' : 'No'}</dd>
              <dt>Human reviewed</dt><dd>{answer.human_reviewed ? 'Yes' : 'No'}</dd>
              <dt>Current version</dt><dd>{answer.current_version ?? 0}</dd>
              <dt>Published at</dt><dd>{answer.published_at ?? '—'}</dd>
              <dt>Public URL</dt><dd>
                {answer.public_url
                  ? <a href={answer.public_url} target="_blank" rel="noreferrer">{answer.public_url}</a>
                  : '—'}
              </dd>
            </dl>
          </div>

          <div className="card">
            <h2 className="card__title">Version history ({versions.length})</h2>
            <table className="data-table data-table--compact">
              <thead>
                <tr><th>v</th><th>Source</th><th>Created</th></tr>
              </thead>
              <tbody>
                {versions.length === 0 ? (
                  <tr><td colSpan={3} className="muted">No versions yet.</td></tr>
                ) : versions.map(v => (
                  <tr key={v.id}>
                    <td>{v.version_number}</td>
                    <td>{v.creation_reason ?? '—'}</td>
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
