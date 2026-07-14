import { useState } from 'react';
import api from '../../services/api';
import { usePermission } from '../../hooks/usePermission';

/**
 * Editorial approval actions for a video script.
 *
 * Implements the governed workflow: submit → approve / reject / request_changes.
 * Self-approval prevention is enforced server-side; this component visually
 * disables the approve button when the current user is the submitter.
 *
 * Props:
 *  - projectUuid {string} — The video project UUID.
 *  - script      {object} — Current script record (workflow_status, submitted_by).
 *  - currentUser {object} — Logged-in user (id).
 *  - onUpdate    {Function(script)} — Called with updated script after a workflow action.
 */
export function VideoApprovalActions({ projectUuid, script, currentUser, onUpdate }) {
  const { has } = usePermission();
  const [loading, setLoading]   = useState(false);
  const [reason, setReason]     = useState('');
  const [notes, setNotes]       = useState('');
  const [showForm, setShowForm] = useState(null); // 'reject' | 'changes' | null

  const status      = script?.workflow_status ?? '';
  const submittedBy = script?.submitted_by ?? null;
  const isSelf      = submittedBy === currentUser?.id;

  const act = async (endpoint, payload = {}) => {
    setLoading(true);
    try {
      const res = await api.post(`/video/projects/${projectUuid}/script/${endpoint}`, payload);
      onUpdate?.(res.data?.data ?? script);
      setShowForm(null);
      setReason('');
      setNotes('');
    } catch (e) {
      alert(e.response?.data?.message ?? e.message ?? 'Action failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="approval-actions card">
      <h3 className="card__title">Script workflow</h3>

      <div className="workflow-status mb-3">
        <span className="text-muted">Status: </span>
        <span className="badge badge--info">{status.replace(/_/g, ' ')}</span>
      </div>

      {status === 'draft' && has('video.submit') && (
        <div>
          <p className="text-muted mb-2">Submit this script version for editorial review.</p>
          <button
            className="btn btn--primary"
            onClick={() => act('submit')}
            disabled={loading}
          >
            {loading ? 'Submitting…' : 'Submit for review'}
          </button>
        </div>
      )}

      {status === 'in_review' && has('video.approve') && (
        <div>
          {isSelf && (
            <div className="alert alert--warning mb-3">
              Self-approval is not permitted. You submitted this version and cannot also approve it.
            </div>
          )}

          <div className="btn-group">
            <button
              className="btn btn--success"
              onClick={() => act('approve')}
              disabled={loading || isSelf}
              title={isSelf ? 'Cannot approve your own submission' : 'Approve script'}
            >
              {loading ? '…' : 'Approve'}
            </button>

            <button
              className="btn btn--outline ml-2"
              onClick={() => setShowForm(showForm === 'changes' ? null : 'changes')}
              disabled={loading}
            >
              Request changes
            </button>

            <button
              className="btn btn--error ml-2"
              onClick={() => setShowForm(showForm === 'reject' ? null : 'reject')}
              disabled={loading}
            >
              Reject
            </button>
          </div>

          {showForm === 'changes' && (
            <div className="mt-3">
              <textarea
                className="form-textarea"
                rows={3}
                placeholder="Describe the requested changes…"
                value={notes}
                onChange={e => setNotes(e.target.value)}
              />
              <button
                className="btn btn--primary mt-2"
                onClick={() => act('request-changes', { notes })}
                disabled={loading || !notes.trim()}
              >
                Send request
              </button>
            </div>
          )}

          {showForm === 'reject' && (
            <div className="mt-3">
              <textarea
                className="form-textarea"
                rows={3}
                placeholder="Rejection reason…"
                value={reason}
                onChange={e => setReason(e.target.value)}
              />
              <button
                className="btn btn--error mt-2"
                onClick={() => act('reject', { reason })}
                disabled={loading || !reason.trim()}
              >
                Confirm rejection
              </button>
            </div>
          )}
        </div>
      )}

      {status === 'approved' && (
        <p className="text-success">Script approved. Ready for render queue.</p>
      )}
      {status === 'rejected' && (
        <p className="text-error">Script rejected. A new revision is required.</p>
      )}
      {status === 'changes_requested' && (
        <p className="text-warning">Changes requested. Revise and resubmit.</p>
      )}
    </div>
  );
}
