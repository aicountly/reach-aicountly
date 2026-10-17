import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Lightbulb, Play, CheckCircle, AlertTriangle, XCircle, HelpCircle, CircleDot } from 'lucide-react';
import {
  getLatestInsights,
  listInsights,
  getInsight,
  runInsights,
  updateInsightAction,
  listStrategyMemos,
  getStrategyMemo,
} from '../../services/growthIntelService.js';
import { usePermission } from '../../hooks/usePermission';
import { formatDateTime } from '../../utils/formatDate';

const STATUS = {
  done:    { cls: 'badge-success',   Icon: CheckCircle,   label: 'Done' },
  partial: { cls: 'badge-warning',   Icon: CircleDot,     label: 'Partial' },
  missing: { cls: 'badge-danger',    Icon: XCircle,       label: 'Missing' },
  unknown: { cls: 'badge-secondary', Icon: HelpCircle,    label: 'Unknown' },
};
const IMPACT = { high: 'badge-success', medium: 'badge-info', low: 'badge-secondary' };

function Section({ title, children, empty }) {
  return (
    <div className="card mb-3">
      <div className="card-header">{title}</div>
      <div className="card-body">{children || <p className="text-sm text-muted" style={{ margin: 0 }}>{empty || 'Nothing reported.'}</p>}</div>
    </div>
  );
}

/**
 * AI Insights: the weekly (or on-demand) report the AI Insights model writes
 * from Reach's own data digest, the "Act on this" list, and the weekly
 * growth-strategy memos from the Claude routine.
 */
export default function AiInsightsPage() {
  const { has } = usePermission();
  const canManage = has('intelligence.manage');
  const [params, setParams] = useSearchParams();
  const [state, setState] = useState(null);       // latest endpoint payload
  const [report, setReport] = useState(null);     // report being shown
  const [history, setHistory] = useState([]);
  const [memos, setMemos] = useState([]);
  const [memo, setMemo] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    return Promise.allSettled([getLatestInsights(), listInsights(), listStrategyMemos()])
      .then(([l, h, m]) => {
        if (l.status === 'fulfilled') {
          setState(l.value);
          setReport((cur) => (cur && l.value?.report && cur.id !== l.value.report.id ? cur : l.value?.report ?? null));
        } else {
          setError(l.reason?.message || 'Failed to load AI Insights');
        }
        if (h.status === 'fulfilled') setHistory(Array.isArray(h.value) ? h.value : []);
        if (m.status === 'fulfilled') setMemos(Array.isArray(m.value) ? m.value : []);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const memoId = params.get('memo');
  useEffect(() => {
    if (!memoId) { setMemo(null); return; }
    getStrategyMemo(memoId).then(setMemo).catch((e) => setError(e.message));
  }, [memoId]);

  const pickReport = (id) => {
    getInsight(id).then(setReport).catch((e) => setError(e.message));
  };

  const run = async () => {
    setNotice(null);
    try {
      const res = await runInsights();
      setNotice({ kind: 'success', text: res?.message || 'Queued.' });
      load();
    } catch (e) {
      setNotice({ kind: 'warning', text: e.message || 'Could not queue a run.' });
    }
  };

  const setActionStatus = async (action, status) => {
    try {
      const updated = await updateInsightAction(action.id, status);
      setReport((r) => (r ? { ...r, actions: (r.actions || []).map((a) => (a.id === updated.id ? updated : a)) } : r));
    } catch (e) {
      setError(e.message);
    }
  };

  const body = report?.report;
  const actions = report?.actions ?? [];
  const lastRun = state?.last_run;

  return (
    <div>
      <div className="flex items-center justify-between flex-wrap gap-3 mb-4">
        <div className="flex items-center gap-3">
          <Lightbulb size={26} style={{ color: 'var(--color-primary)' }} aria-hidden="true" />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>AI Insights</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              What is working, what we lack, and what to do next — written by {state?.model || 'the AI Insights model'} from Reach&apos;s own data. Weekly on Sundays, or on demand.
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {history.length > 0 && (
            <select className="form-select form-select--sm" aria-label="Report" value={report?.id ?? ''} onChange={(e) => pickReport(e.target.value)}>
              {history.map((h) => (
                <option key={h.id} value={h.id} disabled={h.status !== 'completed'}>
                  #{h.id} · {h.created_at ? formatDateTime(h.created_at) : ''} · {h.status}
                </option>
              ))}
            </select>
          )}
          {canManage && (
            <button type="button" className="btn btn-primary btn-sm" onClick={run} disabled={state?.run_pending}>
              <Play size={14} aria-hidden="true" /> {state?.run_pending ? 'Run queued…' : 'Run now'}
            </button>
          )}
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {notice && <div className={`alert alert-${notice.kind}`}>{notice.text}</div>}
      {state && !state.configured && (
        <div className="alert alert-warning">AI Insights needs the Gemini API key (Settings → AI &amp; Automation). No report is produced without it.</div>
      )}
      {lastRun?.status === 'failed' && (
        <div className="alert alert-warning">Last run failed ({lastRun.created_at ? formatDateTime(lastRun.created_at) : ''}): {lastRun.error}</div>
      )}

      {loading && !state && <p className="text-sm text-muted">Loading…</p>}
      {!loading && !report && (
        <div className="card mb-3"><div className="card-body text-sm text-muted">No completed AI Insights report yet.</div></div>
      )}

      {memo && (
        <div className="card mb-3">
          <div className="card-header flex items-center justify-between">
            <span>Strategy memo: {memo.title}</span>
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setParams({})}>Close</button>
          </div>
          <div className="card-body">
            <p className="text-xs text-muted" style={{ marginTop: 0 }}>
              {memo.period_start && `${memo.period_start} – ${memo.period_end || ''} · `}{memo.model || 'routine'} · {memo.created_at ? formatDateTime(memo.created_at) : ''}
            </p>
            <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit', margin: 0 }}>{memo.body_markdown}</pre>
          </div>
        </div>
      )}

      {report && body && (
        <>
          <p className="text-xs text-muted">
            Report #{report.id} · data {report.period_start} – {report.period_end} · {report.model} · {report.created_at ? formatDateTime(report.created_at) : ''}
          </p>

          {(report.unverified_numbers ?? []).length > 0 && (
            <div className="alert alert-warning">
              <AlertTriangle size={14} aria-hidden="true" style={{ verticalAlign: '-2px' }} /> {report.unverified_numbers.length} figure(s) in this report could not be traced to the data digest and may be the model&apos;s own: <strong>{report.unverified_numbers.join(', ')}</strong>. Treat them as unverified.
            </div>
          )}

          <Section title="Summary">{body.summary ? <p style={{ margin: 0 }}>{body.summary}</p> : null}</Section>

          <div className="card mb-3">
            <div className="card-header">Act on this — next two weeks</div>
            <div className="card-body" style={{ padding: 0 }}>
              <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
                <table className="data-table">
                  <thead><tr><th>#</th><th>Action</th><th>Impact</th><th>Effort</th><th>Owner</th><th>Status</th><th /></tr></thead>
                  <tbody>
                    {actions.length === 0 && <tr><td colSpan={7} className="text-sm text-muted">No recommendations in this report.</td></tr>}
                    {actions.map((a) => (
                      <tr key={a.id} style={a.status !== 'open' ? { opacity: 0.6 } : undefined}>
                        <td>{a.position}</td>
                        <td>
                          <div style={{ fontWeight: 600, textDecoration: a.status === 'done' ? 'line-through' : 'none' }}>{a.title}</div>
                          <div className="text-xs text-muted">{a.detail}</div>
                          {a.impact_rationale && <div className="text-xs text-muted">Why: {a.impact_rationale}</div>}
                        </td>
                        <td><span className={`badge ${IMPACT[a.expected_impact] || 'badge-secondary'}`}>{a.expected_impact}</span></td>
                        <td>{a.effort}{a.horizon_days ? ` · ${a.horizon_days}d` : ''}</td>
                        <td>{a.owner_type}</td>
                        <td>{a.status}</td>
                        <td>
                          {canManage && (
                            <div className="flex gap-1">
                              {a.status === 'open' ? (
                                <>
                                  <button type="button" className="btn btn-primary btn-sm" onClick={() => setActionStatus(a, 'done')}>Mark done</button>
                                  <button type="button" className="btn btn-secondary btn-sm" onClick={() => setActionStatus(a, 'dismissed')}>Dismiss</button>
                                </>
                              ) : (
                                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setActionStatus(a, 'open')}>Reopen</button>
                              )}
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div className="grid grid-2">
            <Section title="What's working" empty="Nothing the data shows as working yet.">
              {(body.whats_working ?? []).length > 0 && (
                <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
                  {body.whats_working.map((w, i) => <li key={i}>{w.finding}<div className="text-xs text-muted">{w.evidence}</div></li>)}
                </ul>
              )}
            </Section>
            <Section title="Gaps — what we lack">
              {(body.gaps ?? []).length > 0 && (
                <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
                  {body.gaps.map((g, i) => <li key={i}><strong>{g.area}:</strong> {g.finding}<div className="text-xs text-muted">{g.evidence}</div></li>)}
                </ul>
              )}
            </Section>
          </div>

          <div className="card mb-3">
            <div className="card-header">AI recommendation readiness</div>
            <div className="card-body" style={{ padding: 0 }}>
              <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
                <table className="data-table">
                  <thead><tr><th>Signal</th><th>Status</th><th>Evidence</th><th>Next step</th></tr></thead>
                  <tbody>
                    {(body.ai_readiness_checklist ?? []).map((c) => {
                      const s = STATUS[c.status] || STATUS.unknown;
                      return (
                        <tr key={c.key}>
                          <td>{c.item}{c.source === 'model' && <div className="text-xs text-muted">suggested by the model, not checked</div>}</td>
                          <td><span className={`badge ${s.cls}`}><s.Icon size={12} aria-hidden="true" style={{ verticalAlign: '-2px' }} /> {s.label}</span></td>
                          <td className="text-xs">{c.evidence}</td>
                          <td className="text-xs">{c.next_step}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div className="grid grid-2">
            <Section title="Going global — markets to test">
              {((body.internationalisation?.markets_to_test ?? []).length > 0 || (body.internationalisation?.localisation_needs ?? []).length > 0) && (
                <>
                  {(body.internationalisation?.markets_to_test ?? []).map((m, i) => (
                    <div key={i} className="mb-2"><strong>{m.market}</strong> — {m.rationale}<div className="text-xs text-muted">First step: {m.first_step}</div></div>
                  ))}
                  {(body.internationalisation?.localisation_needs ?? []).length > 0 && (
                    <>
                      <div className="font-semibold mt-2">Localisation needs</div>
                      <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>{body.internationalisation.localisation_needs.map((n, i) => <li key={i}>{n}</li>)}</ul>
                    </>
                  )}
                </>
              )}
            </Section>
            <Section title="Investor readiness">
              {(body.investor_readiness?.metrics_investors_ask ?? []).length > 0 && (
                <>
                  <table className="data-table">
                    <thead><tr><th>Metric investors ask for</th><th>We have it?</th><th>Evidence / gap</th></tr></thead>
                    <tbody>
                      {body.investor_readiness.metrics_investors_ask.map((m, i) => (
                        <tr key={i}>
                          <td>{m.metric}</td>
                          <td>{m.have_data ? <span className="badge badge-success">Yes</span> : <span className="badge badge-danger">No</span>}</td>
                          <td className="text-xs">{m.evidence_or_gap}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {(body.investor_readiness.narrative_points ?? []).length > 0 && (
                    <><div className="font-semibold mt-3">Narrative</div><ul style={{ margin: 0, paddingLeft: '1.1rem' }}>{body.investor_readiness.narrative_points.map((n, i) => <li key={i}>{n}</li>)}</ul></>
                  )}
                  {(body.investor_readiness.data_room_gaps ?? []).length > 0 && (
                    <><div className="font-semibold mt-3">Data-room gaps</div><ul style={{ margin: 0, paddingLeft: '1.1rem' }}>{body.investor_readiness.data_room_gaps.map((n, i) => <li key={i}>{n}</li>)}</ul></>
                  )}
                </>
              )}
            </Section>
          </div>

          <Section title="Assumptions (not from Reach data)" empty="The model listed no assumptions.">
            {(body.assumptions ?? []).length > 0 && (
              <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>{body.assumptions.map((a, i) => <li key={i}>{a}</li>)}</ul>
            )}
          </Section>

          {report.digest && (
            <details className="card mb-3">
              <summary className="card-header" style={{ cursor: 'pointer' }}>Data digest sent to the model</summary>
              <div className="card-body">
                <pre style={{ whiteSpace: 'pre-wrap', fontSize: '0.75rem', maxHeight: 420, overflow: 'auto', margin: 0 }}>{JSON.stringify(report.digest, null, 2)}</pre>
              </div>
            </details>
          )}
        </>
      )}

      <div className="card">
        <div className="card-header">Weekly growth-strategy memos</div>
        <div className="card-body">
          {memos.length === 0 && <p className="text-sm text-muted" style={{ margin: 0 }}>No memos yet. The reach-growth-strategy routine posts one every Sunday at 18:00 IST.</p>}
          <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
            {memos.map((m) => (
              <li key={m.id}>
                <button type="button" className="link-btn" onClick={() => setParams({ memo: String(m.id) })}>{m.title}</button>
                <span className="text-xs text-muted"> · {m.created_at ? formatDateTime(m.created_at) : ''}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
