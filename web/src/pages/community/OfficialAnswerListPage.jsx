import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { DeleteButton } from '../../components/common/DeleteButton';
import { usePermission } from '../../hooks/usePermission';
import { normalizeCommunityList, normalizeCommunityMeta } from './communityListUtils';

// Values mirror App\Enums\CommunityAnswerStatus — the old list used
// invented statuses (draft/generated/pending_approval) that exist nowhere,
// so most filters returned nothing.
const STATUS_OPTS = [
  { value: '', label: 'All' },
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
  { value: 'published', label: 'Published' },
  { value: 'withdrawn', label: 'Withdrawn' },
];
const STATUS_CLASS = {
  draft_requested: 'badge--neutral',
  generating: 'badge--info',
  draft_generated: 'badge--info',
  validation_failed: 'badge--error',
  moderation_required: 'badge--warning',
  editorial_review: 'badge--warning',
  professional_review: 'badge--warning',
  changes_requested: 'badge--warning',
  approved: 'badge--info',
  scheduled: 'badge--info',
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
  const [reloadKey, setReloadKey] = useState(0);
  const { has } = usePermission();
  const canDelete = has('community_answer.delete');

  useEffect(() => {
    setLoading(true);
    api.get('v1/community/answers', { status: status || undefined, page })
      .then(r => {
        setAnswers(normalizeCommunityList(r));
        setTotalPages(normalizeCommunityMeta(r).last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [status, page, reloadKey]);

  return (
    <div>
      <div className="page-header">
        <h1>Official Answers</h1>
      </div>

      <div className="toolbar mb-3">
        <label className="toolbar__label">
          Status
          <select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} className="form-select form-select--sm">
            {STATUS_OPTS.map(s => <option key={s.value || 'all'} value={s.value}>{s.label}</option>)}
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
              // The API row's public identifier is `uuid` (external_id does not
              // exist on this table) — using it keeps the Edit link resolvable.
              <tr key={a.id}>
                <td><code className="text-xs">{(a.uuid ?? a.external_id)?.slice(0, 12)}…</code></td>
                <td><span className={`badge ${STATUS_CLASS[a.status] ?? 'badge--neutral'}`}>{a.status}</span></td>
                <td>{a.risk_classification ?? '—'}</td>
                <td>{a.ai_assisted ? 'Yes' : 'No'}</td>
                <td>{a.human_reviewed ? 'Yes' : 'No'}</td>
                <td>{a.updated_at ? new Date(a.updated_at).toLocaleDateString() : '—'}</td>
                <td>
                  <Link to={`/community/answers/${a.uuid ?? a.external_id}`} className="btn btn--sm">Edit</Link>
                  {canDelete && (
                    <DeleteButton
                      className="btn btn--sm btn--danger ml-2"
                      confirmMessage={
                        'Permanently delete this official answer?\n\n'
                        + 'Its versions, approvals and deployment records go with it, and a '
                        + 'published answer is removed from the public site. This cannot be undone.'
                      }
                      onDelete={(force) => api.delete(
                        `v1/community/answers/${a.uuid ?? a.external_id}`,
                        { reason: 'Deleted from the official answers list', force },
                      )}
                      onDeleted={() => setReloadKey((k) => k + 1)}
                      onError={(msg) => msg && alert(msg)}
                    />
                  )}
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
