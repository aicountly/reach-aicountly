import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { RefreshCw, Wrench } from 'lucide-react';
import { useUrlFilter } from '../drill/useUrlFilter';
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
  awaiting_rewrite: 'badge--warning',
};

const BLOCKED = 'publish_blocked,publish_failed';

/** Buttons for RoutineRemediator steps (the server says which apply to a row). */
const STEP_BUTTONS = {
  revet: { label: 'Re-vet & publish', busy: 'Re-vetting…', title: 'Send the current text back through Gemini vetting; it publishes if Gemini approves (with its corrections).' },
  rewrite: { label: 'Ask bot to rewrite', busy: 'Queuing…', title: 'The routine writes a fresh version on the same topic and URL, with Gemini’s reasons; Gemini vets it before it publishes.' },
};

const NEXT_STEP_LABEL = {
  retry: 'Bot will retry it',
  revet: 'Bot will re-vet it',
  rewrite: 'Bot will ask for a rewrite',
  wait: 'Bot is waiting',
  person: 'Needs a person',
};

const DECISION_LABEL = {
  approved: 'Approved',
  revised_approved: 'Approved after corrections',
  rejected: 'Rejected',
  revise_unresolved: 'Rejected (still needed changes after one re-vet)',
};

const FILTERS = [
  { value: '', label: 'All' },
  { value: BLOCKED, label: 'Blocked from publishing' },
  { value: 'pending_vetting,awaiting_cover,publish_blocked,publish_failed,approved,awaiting_rewrite', label: 'Needs attention' },
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
 * cover, how far publishing got — Retry for the ones that stalled, and the
 * bot's fixes for the ones blocked from publishing (re-vet the current text,
 * or a same-URL rewrite by the routine). `?status=` in the URL pre-filters the
 * list, so the Command centre's card lands on the blocked ones.
 */
export function RoutineSubmissionsPanel({ contentType, title = 'Routine submissions' }) {
  const [data, setData] = useState({ items: [], quota: {}, flags: {}, auto_remediation: true });
  const [status, setStatus] = useUrlFilter('status');
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
      .then((d) => setData({ items: d?.items ?? [], quota: d?.quota ?? {}, flags: d?.flags ?? {}, auto_remediation: d?.auto_remediation !== false }))
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

  const remedy = async (row, step) => {
    let note;
    if (step === 'rewrite' && typeof window.prompt === 'function') {
      note = window.prompt('Anything the routine should know for the rewrite? (optional)', '');
      if (note === null) return;
    }
    setBusy(`${row.id}:${step}`);
    setError(null);
    setNotice(null);
    try {
      const after = await routineSubmissionService.remedy(row.id, step, note || undefined);
      setNotice(`“${row.title ?? `#${row.content_item_id}`}”: ${after?.status_label ?? after?.status ?? 'done'}.${after?.last_error ? ` ${after.last_error}` : ''}`);
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(null);
    }
  };

  const fixAll = async () => {
    setBusy('all');
    setError(null);
    setNotice(null);
    try {
      const r = await routineSubmissionService.fixBlocked();
      setNotice(r?.message ?? 'Done.');
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(null);
    }
  };

  const quota = data.quota?.[contentType];
  const blockedCount = data.items.filter((r) => BLOCKED.split(',').includes(r.status)).length;
  const known = FILTERS.some((f) => f.value === status);

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="card-header">
        <div>
          <strong>{title}</strong>
          <div className="text-sm text-muted">
            Written by the Claude routine, vetted by Gemini (final decision), approved by the Reach System Bot.
            {quota ? ` Today: ${quota.used_today}/${quota.daily_limit}.` : ''}
            {data.auto_remediation === false
              ? ' The bot’s automatic fixes are off (Settings → AI & Automation → Bot fixes); use the buttons.'
              : ' Blocked ones are fixed by the bot within minutes (retry, re-vet, then a rewrite); the buttons do it now.'}
          </div>
        </div>
        <div className="flex gap-2">
          {canRetry && blockedCount > 0 && (
            <button type="button" className="btn btn--sm btn--primary" disabled={busy === 'all'} onClick={fixAll}>
              <Wrench size={14} aria-hidden="true" /> {busy === 'all' ? 'Fixing…' : `Let the bot fix ${blockedCount} blocked`}
            </button>
          )}
          <select aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)} className="input input--sm">
            {!known && <option value={status}>Status: {status.replace(/_/g, ' ')}</option>}
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
                      {row.remediation?.reason && row.remediation.next_step !== 'none' && (
                        <div className={`text-sm ${row.remediation.next_step === 'person' ? 'text-warning' : 'text-muted'}`} style={{ marginTop: 4, maxWidth: 280 }} data-testid="remediation">
                          <strong>{NEXT_STEP_LABEL[row.remediation.next_step] ?? row.remediation.next_step}:</strong> {row.remediation.reason}
                          {row.remediation.steps_taken > 0 ? ` (${row.remediation.steps_taken} fix${row.remediation.steps_taken === 1 ? '' : 'es'} tried so far)` : ''}
                        </div>
                      )}
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
                      <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
                        {row.retryable && canRetry && (
                          <button type="button" className="btn btn--sm btn--secondary" disabled={busy === row.id} onClick={() => retry(row)}>
                            {busy === row.id ? 'Retrying…' : 'Retry'}
                          </button>
                        )}
                        {canRetry && (row.remediation?.available ?? []).filter((st) => STEP_BUTTONS[st]).map((st) => (
                          <button key={st} type="button" className="btn btn--sm btn--secondary" title={STEP_BUTTONS[st].title}
                                  disabled={busy === `${row.id}:${st}`} onClick={() => remedy(row, st)}>
                            {busy === `${row.id}:${st}` ? STEP_BUTTONS[st].busy : STEP_BUTTONS[st].label}
                          </button>
                        ))}
                      </div>
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
