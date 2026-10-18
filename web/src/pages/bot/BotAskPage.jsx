import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { MessageSquareText, RefreshCw, Send } from 'lucide-react';
import { botActionsService, newIdempotencyKey } from '../../services/botActionsService';
import { usePermission } from '../../hooks/usePermission';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { Loader } from '../../components/common/Loader';
import { formatDateTime } from '../../utils/formatDate';

const OPEN = ['firing', 'submitted', 'in_progress'];
const LABEL = {
  firing: 'Sending',
  submitted: 'Sent to the bot',
  outcome_unknown: 'Outcome unknown',
  in_progress: 'In progress',
  completed: 'Completed',
  partial: 'Partly done',
  failed: 'Failed',
};
const TONE = {
  submitted: 'var(--color-info)',
  in_progress: 'var(--color-info)',
  completed: 'var(--color-success)',
  partial: 'var(--color-warning)',
  outcome_unknown: 'var(--color-warning)',
  failed: 'var(--color-danger)',
};

function Status({ status }) {
  return <span className="text-xs" style={{ fontWeight: 700, color: TONE[status] || 'inherit' }}>{(LABEL[status] || status || '').toUpperCase()}</span>;
}

function Artifacts({ items }) {
  if (!items?.length) return null;
  return (
    <ul className="text-xs" style={{ margin: '0.35rem 0 0', paddingLeft: '1rem' }}>
      {items.map((x) => (
        <li key={`${x.type}-${x.ref}`}>
          {x.type === 'video' && <>Video “{x.title}” ({x.format}): <strong>{x.status}</strong>{x.step ? ` · ${x.step}` : ''}{x.url && <> · <a href={x.url} target="_blank" rel="noreferrer">watch</a></>}{x.error && <span style={{ color: 'var(--color-danger)' }}> · {x.error}</span>}</>}
          {x.type === 'social_post' && <>{x.channel} post #{x.ref}: <strong>{x.status}</strong>{x.url && <> · <a href={x.url} target="_blank" rel="noreferrer">view</a></>}{x.error && <span style={{ color: 'var(--color-danger)' }}> · {x.error}</span>}</>}
          {x.type === 'social_submission' && <>Social post {x.status}{x.reasons?.length ? `: ${x.reasons.join(' ')}` : ''}</>}
          {x.link && x.type !== 'social_submission' && <> · <Link to={x.link}>open</Link></>}
        </li>
      ))}
    </ul>
  );
}

/**
 * Bot → Ask the bot. The owner types what the bot should do now; Reach fires
 * the reach-command Claude Routine once with that instruction and context, and
 * the routine does it through Reach's automation API (Gemini vets, Reach
 * publishes) and reports back here.
 */
export function BotAskPage() {
  const { has } = usePermission();
  const canAsk = has('bot.command') || has('settings.manage');
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loadError, setLoadError] = useState('');
  const [notice, setNotice] = useState(null);
  const [instruction, setInstruction] = useState('');
  const [channels, setChannels] = useState([]);
  const [attachments, setAttachments] = useState('');
  const [sending, setSending] = useState(false);
  // One key per draft: a double click or a retried request returns the same command.
  const draftKey = useRef(newIdempotencyKey('cmd'));

  const load = useCallback(() => (
    botActionsService.commands()
      .then((d) => {
        setData(d);
        setLoadError('');
        setChannels((prev) => (prev.length ? prev : (d.channels || []).filter((c) => c.ready).map((c) => c.channel)));
      })
      .catch((e) => setLoadError(e?.message || 'Unable to load commands.'))
  ), []);

  useEffect(() => { load(); }, [load]);

  const commands = data?.commands || [];
  const anyOpen = commands.some((c) => OPEN.includes(c.status));
  useEffect(() => {
    if (!anyOpen) return undefined;
    const t = setInterval(load, 20000);
    return () => clearInterval(t);
  }, [anyOpen, load]);

  const toggle = (ch) => setChannels((prev) => (prev.includes(ch) ? prev.filter((c) => c !== ch) : [...prev, ch]));

  const send = async (e) => {
    e.preventDefault();
    if (!instruction.trim()) return;
    setSending(true);
    setNotice(null);
    setError('');
    try {
      const res = await botActionsService.sendCommand({
        instruction: instruction.trim(),
        channels,
        attachments: attachments.split(/\s+/).map((s) => s.trim()).filter(Boolean),
        idempotencyKey: draftKey.current,
      });
      const cmd = res?.command;
      if (cmd?.status === 'submitted') {
        setNotice({ variant: 'success', text: 'Sent. The bot is working on it in a Claude session; it reports back here when done.', link: cmd.session_url });
      } else if (cmd?.status === 'outcome_unknown') {
        setNotice({ variant: 'warning', text: cmd.fire_error || 'Sent, but the answer was lost. Check claude.ai → Routines → reach-command before sending it again.' });
      } else {
        setNotice({ variant: 'info', text: `Command ${LABEL[cmd?.status] || cmd?.status || ''}.`, link: cmd?.session_url });
      }
      setInstruction('');
      setAttachments('');
      draftKey.current = newIdempotencyKey('cmd');
      await load();
    } catch (err) {
      const wait = err?.retryAfter ? ` Try again in ${err.retryAfter} s.` : '';
      setError(`${err?.message || 'The command was not sent.'}${wait}`);
      // A refused fire is stored with its reason; a new attempt is a new command.
      if (err?.status === 502) draftKey.current = newIdempotencyKey('cmd');
      await load();
    } finally {
      setSending(false);
    }
  };

  if (!data && !loadError) return <Loader label="Loading…" />;
  const configured = !!data?.configured;

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <MessageSquareText size={26} style={{ color: 'var(--color-info)' }} />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Ask the bot</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Tell the bot what to do now. It runs as a Claude routine, submits through Reach (Gemini vets everything, Reach publishes) and reports back here.
            </p>
          </div>
        </div>
        <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={sending}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {loadError && <Alert variant="danger">{loadError}</Alert>}
      {error && <Alert variant="danger">{error}</Alert>}
      {notice && (
        <Alert variant={notice.variant}>
          {notice.text} {notice.link && <a href={notice.link} target="_blank" rel="noreferrer">Open the session</a>}
        </Alert>
      )}

      {data && !configured && (
        <Alert variant="warning">
          <strong>The bot’s command routine is not set up yet, so nothing can be sent.</strong>
          {data.configuration?.problems?.length > 0 && <div className="text-sm">{data.configuration.problems.join(' ')}</div>}
          <ol className="text-sm" style={{ margin: '0.5rem 0 0', paddingLeft: '1.25rem' }}>
            {(data.setup || []).map((s) => <li key={s}>{s}</li>)}
          </ol>
          <div className="text-sm">Settings live in <Link to="/admin/ai-automation">Settings → AI &amp; Automation</Link> → Bot commands. Full guide: docs/operations/BOT_ACTIONS.md.</div>
        </Alert>
      )}

      {canAsk && (
        <Card title="New command">
          <form onSubmit={send}>
            <label htmlFor="bot-instruction" className="text-sm" style={{ fontWeight: 600 }}>What should the bot do?</label>
            <textarea
              id="bot-instruction"
              className="form-control"
              rows={4}
              maxLength={data?.limits?.instruction_chars || 4000}
              value={instruction}
              onChange={(e) => setInstruction(e.target.value)}
              placeholder={data?.examples?.[0] || ''}
              style={{ width: '100%' }}
            />
            {(data?.examples || []).length > 0 && (
              <div className="text-xs text-muted" style={{ margin: '0.35rem 0 0.75rem' }}>
                Examples:{' '}
                {data.examples.map((ex) => (
                  <button key={ex} type="button" className="btn btn-link btn-sm" onClick={() => setInstruction(ex)} style={{ padding: '0 0.35rem' }}>
                    “{ex}”
                  </button>
                ))}
              </div>
            )}

            <fieldset style={{ border: 0, padding: 0, margin: '0 0 0.75rem' }}>
              <legend className="text-sm" style={{ fontWeight: 600 }}>Channels</legend>
              {(data?.channels || []).length === 0 && <div className="text-xs text-muted">Channel status unavailable.</div>}
              {(data?.channels || []).map((c) => (
                <label key={c.channel} className="text-sm" style={{ display: 'block' }} title={c.reason || ''}>
                  <input type="checkbox" checked={channels.includes(c.channel)} onChange={() => toggle(c.channel)} disabled={!c.ready} />{' '}
                  {c.label || c.channel}
                  {c.ready ? <span className="text-xs text-muted"> · connected{c.account ? ` (${c.account})` : ''}</span> : <span className="text-xs" style={{ color: 'var(--color-warning)' }}> · not connected: {c.reason}</span>}
                </label>
              ))}
              <div className="text-xs text-muted">None ticked = every connected channel.</div>
            </fieldset>

            <label htmlFor="bot-attachments" className="text-sm" style={{ fontWeight: 600 }}>Attachments (optional, https links, one per line)</label>
            <textarea
              id="bot-attachments"
              className="form-control"
              rows={2}
              value={attachments}
              onChange={(e) => setAttachments(e.target.value)}
              placeholder="https://…/logo.png"
              style={{ width: '100%' }}
            />

            <div style={{ marginTop: '0.75rem' }}>
              <button type="submit" className="btn btn-primary" disabled={!configured || sending || instruction.trim().length < 3}>
                <Send size={14} /> {sending ? 'Sending…' : 'Send to the bot'}
              </button>
            </div>
          </form>
        </Card>
      )}

      <Card title={`History (${commands.length})`} padding={false}>
        {commands.length === 0 ? (
          <p className="text-sm text-muted" style={{ margin: 0, padding: '1rem' }}>No commands yet.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>When</th><th>Command</th><th>Status</th><th>Result</th></tr></thead>
              <tbody>
                {commands.map((c) => (
                  <tr key={c.uuid} data-testid={`command-${c.uuid}`}>
                    <td className="text-sm" style={{ whiteSpace: 'nowrap' }}>
                      {formatDateTime(c.created_at)}
                      <div className="text-xs text-muted">{c.created_by || '—'}</div>
                    </td>
                    <td className="text-sm" style={{ maxWidth: '28rem' }}>
                      {c.kind === 'activity_run' && <div className="text-xs text-muted">Run now: {c.activity_key}</div>}
                      {c.instruction}
                      {c.channels?.length > 0 && <div className="text-xs text-muted">Channels: {c.channels.join(', ')}</div>}
                    </td>
                    <td>
                      <Status status={c.status} />
                      {c.fire_error && <div className="text-xs" style={{ color: c.status === 'failed' ? 'var(--color-danger)' : 'var(--color-warning)', maxWidth: '20rem' }}>{c.fire_error}</div>}
                      {c.session_url && <div className="text-xs"><a href={c.session_url} target="_blank" rel="noreferrer">Claude session</a></div>}
                    </td>
                    <td className="text-sm" style={{ maxWidth: '30rem' }}>
                      {c.report_summary ? <div>{c.report_summary}</div> : <span className="text-xs text-muted">{OPEN.includes(c.status) ? 'Waiting for the bot’s report…' : 'No report.'}</span>}
                      {c.report_links?.length > 0 && (
                        <div className="text-xs">{c.report_links.map((l) => <a key={l.url} href={l.url} target="_blank" rel="noreferrer" style={{ marginRight: '0.5rem' }}>{l.label}</a>)}</div>
                      )}
                      <Artifacts items={c.artifacts} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

export default BotAskPage;
