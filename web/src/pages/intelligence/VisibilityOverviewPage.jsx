import { useCallback, useEffect, useMemo, useState } from 'react';
import { Eye, Play, CheckCircle, XCircle } from 'lucide-react';
import { getVisibilitySummary, listVisibilityAnswers, runVisibilityBatch } from '../../services/growthIntelService.js';
import { usePermission } from '../../hooks/usePermission';
import { formatDateTime } from '../../utils/formatDate';
import ShareOfVoiceChart from './ShareOfVoiceChart.jsx';
import { DrillLink, useDrillFilters } from '../../components/drill';

const pct = (v) => (v == null ? '—' : `${Math.round(Number(v) * 1000) / 10}%`);
const PROVIDER_LABEL = { gemini: 'Gemini', openai: 'ChatGPT (OpenAI API)', perplexity: 'Perplexity' };

/**
 * AI Visibility (GEO): how often AI assistants name AICOUNTLY for the buyer
 * questions in the prompt library, versus competitors — measured from stored
 * answers, never estimated.
 */
export default function VisibilityOverviewPage() {
  const { has } = usePermission();
  const [days, setDays] = useState(28);
  const [summary, setSummary] = useState(null);
  const [answers, setAnswers] = useState([]);
  const [provider, setProvider] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [running, setRunning] = useState(false);
  // An assistant row or a gap question narrows "Latest answers" to the
  // answers behind it (the API filters by provider / prompt_id). In the URL so
  // the narrowed view is shareable.
  const answerFilter = useDrillFilters(['provider', 'prompt_id']);
  const { provider: answerProvider, prompt_id: answerPrompt } = answerFilter.filters;

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const answerParams = { limit: 30 };
    if (answerProvider) answerParams.provider = answerProvider;
    if (answerPrompt) answerParams.prompt_id = answerPrompt;
    return Promise.allSettled([getVisibilitySummary(days), listVisibilityAnswers(answerParams)])
      .then(([s, a]) => {
        if (s.status === 'fulfilled') setSummary(s.value);
        else setError(s.reason?.message || 'Failed to load AI visibility');
        if (a.status === 'fulfilled') setAnswers(Array.isArray(a.value) ? a.value : []);
      })
      .finally(() => setLoading(false));
  }, [days, answerProvider, answerPrompt]);

  const showAnswers = (patch) => {
    answerFilter.drill(patch);
    document.getElementById('latest-answers')?.scrollIntoView?.({ behavior: 'smooth' });
  };

  useEffect(() => { load(); }, [load]);

  const byProvider = useMemo(() => summary?.by_provider ?? [], [summary]);
  const activeProvider = provider && byProvider.some((p) => p.provider === provider)
    ? provider
    : byProvider[0]?.provider ?? null;
  const brand = summary?.brand || 'AICOUNTLY';
  const chartEntities = [brand, ...(summary?.top_competitors ?? []).slice(0, 3)];
  const chartPoints = (summary?.series ?? []).filter((r) => r.provider === activeProvider);
  const overall = summary?.overall;
  const competitors = Object.entries(overall?.entities ?? {});

  const runNow = async () => {
    setRunning(true);
    setNotice(null);
    try {
      const batch = await runVisibilityBatch();
      setNotice({ kind: 'success', text: `Queued ${batch?.run_ids?.length ?? 0} answers (${batch?.prompts_planned ?? 0} prompts). Results appear as the worker completes them.` });
      load();
    } catch (e) {
      setNotice({ kind: 'warning', text: e.message || 'Could not start a pass.' });
    } finally {
      setRunning(false);
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between flex-wrap gap-3 mb-4">
        <div className="flex items-center gap-3">
          <Eye size={26} style={{ color: 'var(--color-primary)' }} aria-hidden="true" />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>AI Visibility</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Does ChatGPT, Gemini or Perplexity name {brand} when buyers ask for accounting, billing or inventory software?
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <label className="text-sm text-muted" htmlFor="vis-days">Period</label>
          <select id="vis-days" className="form-select form-select--sm" value={days} onChange={(e) => setDays(Number(e.target.value))}>
            <option value={7}>Last 7 days</option>
            <option value={28}>Last 28 days</option>
            <option value={90}>Last 90 days</option>
          </select>
          {has('visibility.execute') && (
            <button type="button" className="btn btn-primary btn-sm" onClick={runNow} disabled={running}>
              <Play size={14} aria-hidden="true" /> {running ? 'Starting…' : 'Run now'}
            </button>
          )}
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {notice && <div className={`alert alert-${notice.kind}`}>{notice.text}</div>}

      {summary && (
        <div className="card mb-3">
          <div className="card-body">
            <div className="flex flex-wrap gap-2" aria-label="Assistants">
              {(summary.providers ?? []).map((p) => (
                <span key={p.key} className={`badge ${p.will_run ? 'badge-success' : 'badge-secondary'}`} title={p.reason || p.model}>
                  {p.label}: {p.will_run ? `checked daily (${p.model})` : 'skipped'}
                </span>
              ))}
            </div>
            {(summary.providers ?? []).filter((p) => !p.will_run).map((p) => (
              <p key={p.key} className="text-xs text-muted" style={{ margin: '0.4rem 0 0' }}>{p.label} skipped — {p.reason}</p>
            ))}
            <p className="text-xs text-muted" style={{ margin: '0.6rem 0 0' }}>{summary.sample_note}</p>
            {summary.latest_batch && (
              <p className="text-xs text-muted" style={{ margin: '0.25rem 0 0' }}>
                Last pass {summary.latest_batch.batch_date} ({summary.latest_batch.trigger_type}): {summary.latest_batch.status}
                {summary.latest_batch.note ? ` — ${summary.latest_batch.note}` : ''}
              </p>
            )}
          </div>
        </div>
      )}

      {(summary?.failures ?? []).length > 0 && (
        <div className="alert alert-warning">
          {summary.failures.map((f) => (
            <div key={f.provider}>{PROVIDER_LABEL[f.provider] || f.provider}: {f.failed_runs} failed answers. Last error: {f.last_error}</div>
          ))}
        </div>
      )}

      <div className="grid grid-4 mb-3">
        {[
          ['Answers analysed', overall ? String(overall.answers) : '—'],
          [`${brand} mention rate`, overall ? pct(overall.brand_mention_rate) : '—'],
          ['Average rank when listed', overall?.brand_avg_rank != null ? `#${overall.brand_avg_rank}` : '—'],
          [`Answers citing ${brand.toLowerCase()}.com`, overall ? String(overall.brand_cited) : '—'],
        ].map(([label, value]) => (
          <div key={label} className="stat-tile" style={{ textAlign: 'center' }}>
            <div className="stat-tile__value">{loading && !summary ? '…' : value}</div>
            <div className="stat-tile__label">{label}</div>
          </div>
        ))}
      </div>

      <div className="card mb-3">
        <div className="card-header flex items-center justify-between flex-wrap gap-2">
          <span>Share of voice over time</span>
          {byProvider.length > 0 && (
            <div className="btn-group" role="tablist" aria-label="Assistant">
              {byProvider.map((p) => (
                <button key={p.provider} type="button" role="tab" aria-selected={p.provider === activeProvider}
                  className={`btn btn-sm ${p.provider === activeProvider ? 'btn-primary' : 'btn-secondary'}`}
                  onClick={() => setProvider(p.provider)}>
                  {PROVIDER_LABEL[p.provider] || p.provider}
                </button>
              ))}
            </div>
          )}
        </div>
        <div className="card-body">
          <p className="text-xs text-muted" style={{ marginTop: 0 }}>
            Share of voice = mentions of a name ÷ all tracked-name mentions in that day&apos;s answers. Names asked about in the question itself are not counted.
          </p>
          <ShareOfVoiceChart points={chartPoints} entities={chartEntities} />
        </div>
      </div>

      <div className="grid grid-2 mb-3">
        <div className="card">
          <div className="card-header">By assistant</div>
          <div className="card-body" style={{ padding: 0 }}>
            <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
              <table className="data-table">
                <thead><tr><th>Assistant</th><th>Answers</th><th>{brand} named</th><th>Avg rank</th><th>Most named</th></tr></thead>
                <tbody>
                  {byProvider.length === 0 && <tr><td colSpan={5} className="text-sm text-muted">No answers in this period.</td></tr>}
                  {byProvider.map((p) => {
                    const [topName, top] = Object.entries(p.entities ?? {}).find(([n]) => n !== brand) ?? [];
                    return (
                      <tr key={p.provider}>
                        <td>{PROVIDER_LABEL[p.provider] || p.provider}</td>
                        <td>
                          <DrillLink onClick={p.answers > 0 ? () => showAnswers({ provider: p.provider }) : undefined}
                                     title="Show these answers">
                            {p.answers}
                          </DrillLink>
                        </td>
                        <td>{pct(p.brand_mention_rate)}</td>
                        <td>{p.brand_avg_rank != null ? `#${p.brand_avg_rank}` : '—'}</td>
                        <td>{topName ? `${topName} (${pct(top.mention_rate)})` : '—'}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div className="card">
          <div className="card-header">Names in answers (all assistants)</div>
          <div className="card-body" style={{ padding: 0 }}>
            <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
              <table className="data-table">
                <thead><tr><th>Name</th><th>Answers</th><th>Mention rate</th><th>Share of voice</th><th>Avg rank</th></tr></thead>
                <tbody>
                  {competitors.length === 0 && <tr><td colSpan={5} className="text-sm text-muted">No tracked names mentioned yet.</td></tr>}
                  {competitors.map(([name, e]) => (
                    <tr key={name} style={name === brand ? { fontWeight: 700 } : undefined}>
                      <td>{name}</td>
                      <td>{e.mentions}</td>
                      <td>{pct(e.mention_rate)}</td>
                      <td>{pct(e.share_of_voice)}</td>
                      <td>{e.avg_rank != null ? `#${e.avg_rank}` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div className="card mb-3">
        <div className="card-header">Gaps — questions where competitors are named and {brand} is not</div>
        <div className="card-body" style={{ padding: 0 }}>
          <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
            <table className="data-table">
              <thead><tr><th>Buyer question</th><th>Market</th><th>Answers without us</th><th>Named instead</th></tr></thead>
              <tbody>
                {(summary?.gaps ?? []).length === 0 && <tr><td colSpan={4} className="text-sm text-muted">No gaps recorded in this period.</td></tr>}
                {(summary?.gaps ?? []).map((g) => (
                  <tr key={g.prompt_id}>
                    <td>{g.question}</td>
                    <td>{g.market || '—'}</td>
                    <td>
                      <DrillLink onClick={g.answers_without_us > 0 ? () => showAnswers({ prompt_id: g.prompt_id, provider: '' }) : undefined}
                                 title="Show the answers for this question">
                        {g.answers_without_us}
                      </DrillLink>
                      {' '}({g.providers.map((p) => PROVIDER_LABEL[p] || p).join(', ')})
                    </td>
                    <td>{g.competitors.slice(0, 4).map((c) => `${c.name} ×${c.answers}`).join(', ')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div className="grid grid-2 mb-3">
        <div className="card">
          <div className="card-header">By market</div>
          <div className="card-body" style={{ padding: 0 }}>
            <table className="data-table">
              <thead><tr><th>Market</th><th>Answers</th><th>{brand} named</th><th>Most named</th></tr></thead>
              <tbody>
                {(summary?.by_market ?? []).length === 0 && <tr><td colSpan={4} className="text-sm text-muted">No answers yet.</td></tr>}
                {(summary?.by_market ?? []).map((m) => (
                  <tr key={m.market}><td>{m.market}</td><td>{m.answers}</td><td>{pct(m.brand_mention_rate)}</td><td>{m.top_entities.slice(0, 3).join(', ') || '—'}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
        <div className="card">
          <div className="card-header">Most cited sources</div>
          <div className="card-body" style={{ padding: 0 }}>
            <table className="data-table">
              <thead><tr><th>Domain</th><th>Answers citing it</th></tr></thead>
              <tbody>
                {(summary?.top_cited_domains ?? []).length === 0 && <tr><td colSpan={2} className="text-sm text-muted">No cited sources yet.</td></tr>}
                {(summary?.top_cited_domains ?? []).map((d) => (
                  <tr key={d.domain}><td>{d.domain}</td><td>{d.answers}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div className="card" id="latest-answers">
        <div className="card-header flex items-center gap-2 flex-wrap">
          <span>Latest answers</span>
          {answerProvider && (
            <button type="button" className="badge badge-secondary" onClick={() => answerFilter.clear('provider')}
                    aria-label={`Clear assistant ${answerProvider} filter`}>
              {PROVIDER_LABEL[answerProvider] || answerProvider} ✕
            </button>
          )}
          {answerPrompt && (
            <button type="button" className="badge badge-secondary" onClick={() => answerFilter.clear('prompt_id')}
                    aria-label="Clear question filter">
              {(summary?.gaps ?? []).find((g) => String(g.prompt_id) === answerPrompt)?.question || `Question #${answerPrompt}`} ✕
            </button>
          )}
        </div>
        <div className="card-body">
          {answers.length === 0 && (
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              {answerProvider || answerPrompt ? 'No answers match this filter.' : 'No answers recorded yet.'}
            </p>
          )}
          {answers.map((a) => (
            <details key={a.run_id} style={{ borderBottom: '1px solid var(--color-border)', padding: '0.5rem 0' }}>
              <summary style={{ cursor: 'pointer' }}>
                <span className="text-sm">
                  {a.status !== 'completed'
                    ? <span className={`badge ${a.status === 'failed' ? 'badge-danger' : 'badge-secondary'}`}>{a.status}</span>
                    : a.brand_mentioned
                      ? <CheckCircle size={14} style={{ color: 'var(--color-success)', verticalAlign: '-2px' }} aria-label={`${brand} named`} />
                      : <XCircle size={14} style={{ color: 'var(--color-danger)', verticalAlign: '-2px' }} aria-label={`${brand} not named`} />}
                  {' '}<strong>{PROVIDER_LABEL[a.provider] || a.provider}</strong> · {a.question}
                  {a.brand_rank != null && <span className="badge badge-info" style={{ marginLeft: 6 }}>#{a.brand_rank}</span>}
                </span>
                <span className="text-xs text-muted" style={{ marginLeft: 8 }}>{a.asked_at ? formatDateTime(a.asked_at) : ''}</span>
              </summary>
              <div className="text-sm" style={{ marginTop: '0.5rem' }}>
                {a.error && <p className="text-sm" style={{ color: 'var(--color-danger)' }}>{a.error}</p>}
                {a.competitors?.length > 0 && <p className="text-xs text-muted">Named: {a.competitors.map((c) => (c.rank ? `${c.name} (#${c.rank})` : c.name)).join(', ')}</p>}
                {a.answer_text && <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit', background: 'var(--color-bg)', padding: '0.75rem', borderRadius: 6, maxHeight: 360, overflow: 'auto' }}>{a.answer_text}</pre>}
                {a.citations?.length > 0 && (
                  <p className="text-xs text-muted">Sources: {a.citations.slice(0, 8).map((c) => c.domain || c.url).join(', ')}</p>
                )}
                <p className="text-xs text-muted">Model: {a.model} · Market: {a.market || '—'}</p>
              </div>
            </details>
          ))}
        </div>
      </div>
    </div>
  );
}
