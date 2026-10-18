import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarClock, Play, RefreshCw } from 'lucide-react';
import { botActionsService, newIdempotencyKey } from '../../services/botActionsService';
import { usePermission } from '../../hooks/usePermission';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { Loader } from '../../components/common/Loader';
import { formatDateTime } from '../../utils/formatDate';

const LIVE = ['pending', 'reserved', 'processing', 'firing', 'submitted', 'in_progress'];
const TONE = {
  completed: 'var(--color-success)',
  submitted: 'var(--color-info)',
  fired: 'var(--color-info)',
  queued: 'var(--color-info)',
  pending: 'var(--color-info)',
  processing: 'var(--color-info)',
  in_progress: 'var(--color-info)',
  partial: 'var(--color-warning)',
  outcome_unknown: 'var(--color-warning)',
  failed: 'var(--color-danger)',
  dead_letter: 'var(--color-danger)',
  cancelled: 'var(--color-muted, inherit)',
};

function StatusText({ status }) {
  if (!status) return <span className="text-xs text-muted">—</span>;
  return (
    <span className="text-xs" style={{ fontWeight: 700, color: TONE[status] || 'inherit' }}>
      {String(status).replace(/_/g, ' ').toUpperCase()}
    </span>
  );
}

function SmartLink({ to, children }) {
  if (!to) return null;
  if (/^https?:\/\//.test(to)) {
    return <a href={to} target="_blank" rel="noreferrer">{children}</a>;
  }
  return <Link to={to}>{children}</Link>;
}

/** The status shown for an activity: its manual run when that is the newest, else the last run. */
function liveStatus(a) {
  const manual = a.last_manual_run;
  return manual?.job?.status || manual?.command?.status || manual?.status || a.last_run?.status || null;
}

/**
 * Bot → Activities. Every scheduled bot activity with its schedule (IST and
 * UTC), last run and next run, and a Run now button that queues exactly what
 * the schedule would.
 */
export function BotActivitiesPage() {
  const { has } = usePermission();
  const canRun = has('bot.run') || has('settings.manage');
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(null); // { variant, text, link? }
  const [busy, setBusy] = useState(null);
  const timer = useRef(null);

  const load = useCallback(() => (
    botActionsService.activities()
      .then((d) => { setData(d); setError(''); })
      .catch((e) => setError(e?.message || 'Unable to load bot activities.'))
  ), []);

  useEffect(() => { load(); }, [load]);

  const activities = useMemo(() => data?.activities || [], [data]);
  const anyLive = activities.some((a) => LIVE.includes(a.last_manual_run?.job?.status || a.last_manual_run?.command?.status || ''));

  // Live status: poll while a manual run is still queued or running.
  useEffect(() => {
    if (!anyLive) return undefined;
    timer.current = setInterval(load, 15000);
    return () => clearInterval(timer.current);
  }, [anyLive, load]);

  const groups = useMemo(() => {
    const out = new Map();
    for (const a of activities) {
      if (!out.has(a.group)) out.set(a.group, []);
      out.get(a.group).push(a);
    }
    return [...out.entries()];
  }, [activities]);

  const runNow = async (a) => {
    const cost = a.cost_note ? `\n\nCost: ${a.cost_note}` : '';
    if (!window.confirm(`Run “${a.name}” now?${cost}`)) return;
    setBusy(a.key);
    setNotice(null);
    setError('');
    try {
      const res = await botActionsService.runActivity(a.key, newIdempotencyKey(`run-${a.key}`));
      const run = res?.run;
      const link = run?.job?.link || run?.command?.session_url || a.link;
      setNotice({ variant: run?.status === 'failed' ? 'danger' : 'success', text: run?.message || 'Queued.', link });
      await load();
    } catch (e) {
      const wait = e?.retryAfter ? ` (try again in ${e.retryAfter} s)` : '';
      setError(`${e?.message || 'The run was not queued.'}${wait}`);
    } finally {
      setBusy(null);
    }
  };

  if (!data && !error) return <Loader label="Loading bot activities…" />;

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <CalendarClock size={26} style={{ color: 'var(--color-info)' }} />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Bot activities</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Everything the bot runs on its own schedule. Run now queues exactly what the schedule would — the same job, queue and command.
            </p>
          </div>
        </div>
        <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={!!busy}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && (
        <Alert variant={notice.variant}>
          {notice.text} {notice.link && <SmartLink to={notice.link}>Open result</SmartLink>}
        </Alert>
      )}
      {!canRun && <Alert variant="info">You can see the bot’s activities; running them needs the bot.run permission.</Alert>}

      {groups.map(([group, rows]) => (
        <Card key={group} title={group} padding={false}>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr><th>Activity</th><th>Schedule</th><th>Last run</th><th>Next run</th><th /></tr>
              </thead>
              <tbody>
                {rows.map((a) => {
                  const status = liveStatus(a);
                  // The latest run of any trigger, unless it is the manual run already shown.
                  const sameAsManual = a.last_manual_run && a.last_run?.job_id && a.last_run.job_id === a.last_manual_run.job?.id;
                  const last = sameAsManual ? null : a.last_run;
                  return (
                    <tr key={a.key} data-testid={`activity-${a.key}`}>
                      <td style={{ maxWidth: '26rem' }}>
                        <div style={{ fontWeight: 600 }}>
                          {a.link ? <Link to={a.link}>{a.name}</Link> : a.name}
                          {a.enabled === false && <span className="text-xs text-muted"> · switched off</span>}
                        </div>
                        <div className="text-xs text-muted">{a.description}</div>
                        {a.cost_note && <div className="text-xs" style={{ color: 'var(--color-warning)' }}>Cost: {a.cost_note}</div>}
                        {a.command && <code className="text-xs">{a.command}</code>}
                      </td>
                      <td className="text-sm">
                        {a.schedule}
                        <div className="text-xs text-muted">cron (UTC) <code>{a.cron_utc}</code></div>
                      </td>
                      <td className="text-sm">
                        <StatusText status={status} />
                        {a.last_manual_run && (
                          <div className="text-xs text-muted">
                            Run now by {a.last_manual_run.requested_by || 'someone'} · {formatDateTime(a.last_manual_run.created_at)}{' '}
                            <SmartLink to={a.last_manual_run.job?.link || a.last_manual_run.command?.session_url}>details</SmartLink>
                            {(a.last_manual_run.job?.error || a.last_manual_run.command?.error) && (
                              <div style={{ color: 'var(--color-danger)' }}>{a.last_manual_run.job?.error || a.last_manual_run.command?.error}</div>
                            )}
                          </div>
                        )}
                        {last && (
                          <div className="text-xs text-muted">
                            {last.source} · {formatDateTime(last.finished_at || last.queued_at)} <SmartLink to={last.link}>details</SmartLink>
                            {last.error && <div style={{ color: 'var(--color-danger)' }}>{last.error}</div>}
                          </div>
                        )}
                        {!a.last_run && !a.last_manual_run && <div className="text-xs text-muted">{a.log_written_at ? `cron log written ${formatDateTime(a.log_written_at)}` : 'Not recorded in Reach'}</div>}
                        {a.visibility_note && <div className="text-xs text-muted" title={a.visibility_note}>ⓘ {a.kind === 'job' ? 'any trigger' : a.kind}</div>}
                      </td>
                      <td className="text-sm">
                        {a.next_run_ist || '—'}
                        {a.next_run_utc && <div className="text-xs text-muted">{a.next_run_utc.replace('T', ' ').replace('+00:00', ' UTC')}</div>}
                      </td>
                      <td>
                        {canRun && (
                          <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            onClick={() => runNow(a)}
                            disabled={!a.safe_to_run_now || !!busy}
                            title={a.safe_to_run_now ? 'Run now' : a.not_runnable_reason}
                          >
                            <Play size={13} /> {busy === a.key ? 'Queuing…' : 'Run now'}
                          </button>
                        )}
                        {!a.safe_to_run_now && <div className="text-xs text-muted" style={{ maxWidth: '16rem' }}>{a.not_runnable_reason}</div>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      ))}
    </div>
  );
}

export default BotActivitiesPage;
