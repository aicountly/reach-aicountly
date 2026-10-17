import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { RefreshCw } from 'lucide-react';
import { routineSubmissionService } from '../../services/routineSubmissionService';
import { usePermission } from '../../hooks/usePermission';
import { Alert } from '../common/Alert';
import { Loader } from '../common/Loader';
import { formatDate } from '../../utils/formatDate';

const STATUS_BADGES = {
  pending_vetting: 'badge--warning',
  rejected: 'badge--error',
  held_high_risk: 'badge--warning',
  awaiting_cover: 'badge--warning',
  approved: 'badge--info',
  publish_blocked: 'badge--error',
  publishing: 'badge--info',
  live: 'badge--success',
  publish_failed: 'badge--error',
};

const DECISION_LABEL = {
  approved: 'Approved',
  revised_approved: 'Approved after corrections',
  rejected: 'Rejected',
  revise_unresolved: 'Rejected (still needed changes after one re-vet)',
};

const FILTERS = [
  { value: '', label: 'All' },
  { value: 'pending_vetting,awaiting_cover,publish_blocked,publish_failed,approved', label: 'Needs attention' },
  { value: 'held_high_risk', label: 'High risk' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'publishing,live', label: 'Published' },
];

function FlagNotice({ flags, contentType }) {
  const key = contentType === 'knowledge_base' ? 'knowledge_base.auto_publish' : 'blog.auto_publish';
  const flag = flags?.[key];
  if (!flag || flag.enabled) return null;
  return (
    <Alert variant="warning">
      Auto-publish is off
      {flag.source === 'env_kill_switch'
        ? <> because <code>{flag.kill_switch}=false</code> is set in the server .env. Remove that line to let Settings decide.</>
        : <> in Settings → AI &amp; Automation. Approved items wait until it is switched on.</>}
    </Alert>
  );
}

/**
 * Routine submissions for one content type: what Gemini decided and why, the
 * cover, how far publishing got — and Retry for the ones that stalled
 * (vetting unavailable, no cover yet, publish refused).
 */
export function RoutineSubmissionsPanel({ contentType, title = 'Routine submissions' }) {
  const [data, setData] = useState({ items: [], quota: {}, flags: {} });
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(null);
  const { has } = usePermission();
  const canRetry = has('publishing.publish');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    Promise.resolve()
      .then(() => routineSubmissionService.list({ content_type: contentType, status, limit: 100 }))
      .then((d) => setData({ items: d?.items ?? [], quota: d?.quota ?? {}, flags: d?.flags ?? {} }))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [contentType, status]);

  useEffect(() => { load(); }, [load]);

  const retry = async (row) => {
    setBusy(row.id);
    setError(null);
    setNotice(null);
    try {
      const after = await routineSubmissionService.retry(row.id);
      setNotice(`“${row.title ?? `#${row.content_item_id}`}”: ${after?.status_label ?? after?.status ?? 'retried'}.`);
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(null);
    }
  };

  const quota = data.quota?.[contentType];

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="card-header">
        <div>
          <strong>{title}</strong>
          <div className="text-sm text-muted">
            Written by the Claude routine, vetted by Gemini (final decision), approved by the Reach System Bot.
            {quota ? ` Today: ${quota.used_today}/${quota.daily_limit}.` : ''}
          </div>
        </div>
        <div className="flex gap-2">
          <select aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)} className="input input--sm">
            {FILTERS.map((f) => <option key={f.label} value={f.value}>{f.label}</option>)}
          </select>
          <button type="button" className="btn btn--sm btn--secondary" onClick={load} aria-label="Refresh"><RefreshCw size={14} /></button>
        </div>
      </div>
      <div className="card-body">
        <FlagNotice flags={data.flags} contentType={contentType} />
        {error && <Alert variant="error">{error}</Alert>}
        {notice && <Alert variant="success">{notice}</Alert>}
        {loading ? <Loader label="Loading routine submissions…" /> : data.items.length === 0 ? (
          <p className="muted" style={{ margin: 0 }}>No routine submissions{status ? ' with this status' : ''} yet.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  {contentType === 'blog' && <th>Cover</th>}
                  <th>Article</th>
                  <th>Status</th>
                  <th>Vetting decision</th>
                  <th>Updated</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((row) => (
                  <tr key={row.id}>
                    {contentType === 'blog' && (
                      <td style={{ width: 96 }}>
                        {row.cover_url
                          ? <img src={row.cover_url} alt={row.cover_alt || ''} style={{ width: 88, height: 50, objectFit: 'cover', borderRadius: 4 }} />
                          : <span className="text-sm text-muted">{row.cover_status === 'failed' ? 'No cover yet' : '—'}</span>}
                      </td>
                    )}
                    <td>
                      <Link to={`/content/${row.content_item_id}`}>{row.title ?? `#${row.content_item_id}`}</Link>
                      <div className="text-sm text-muted">{row.content_key}</div>
                      {row.public_url && <a href={row.public_url} target="_blank" rel="noreferrer" className="text-sm">View live ↗</a>}
                    </td>
                    <td>
                      <span className={`badge ${STATUS_BADGES[row.status] ?? 'badge--neutral'}`}>{row.status_label}</span>
                      {row.last_error && <div className="text-sm text-muted" style={{ marginTop: 4, maxWidth: 280 }}>{row.last_error}</div>}
                    </td>
                    <td style={{ maxWidth: 360 }}>
                      {row.decision ? (
                        <>
                          <div>{DECISION_LABEL[row.decision] ?? row.decision}{row.risk_flag ? ` · high risk (${row.risk_flag.replace(/_/g, ' ')})` : ''}</div>
                          <div className="text-sm text-muted">
                            {row.vetting_model}{row.vetting_run_id ? ` · run #${row.vetting_run_id}` : ''}
                            {row.scores && Object.keys(row.scores).length > 0
                              ? ` · ${Object.entries(row.scores).map(([k, v]) => `${k} ${v}`).join(', ')}` : ''}
                          </div>
                          {row.reasons?.length > 0 && (
                            <ul className="text-sm" style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                              {row.reasons.map((r) => <li key={r}>{r}</li>)}
                            </ul>
                          )}
                        </>
                      ) : (
                        <span className="text-sm text-muted">
                          Not vetted yet{row.vetting_attempts > 0 ? ` (${row.vetting_attempts} attempt${row.vetting_attempts === 1 ? '' : 's'})` : ''}
                        </span>
                      )}
                    </td>
                    <td>{row.updated_at ? formatDate(row.updated_at) : '—'}</td>
                    <td>
                      {row.retryable && canRetry && (
                        <button type="button" className="btn btn--sm btn--secondary" disabled={busy === row.id} onClick={() => retry(row)}>
                          {busy === row.id ? 'Retrying…' : 'Retry'}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

export default RoutineSubmissionsPanel;
