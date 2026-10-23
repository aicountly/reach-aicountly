import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowDownRight, ArrowUpRight, ChevronDown, ExternalLink, Minus, Radar, Trophy } from 'lucide-react';
import { rankingsDrilldownService } from '../../services/rankingsDrilldownService';
import { Section } from './Sections';
import { num, pct } from './format';
import './rankings.css';

/* ───────────────────────────── helpers ───────────────────────────── */

const istFmt = new Intl.DateTimeFormat('en-IN', {
  timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
});

/** "23 Oct 2026, 06:04 IST" from an ISO timestamp; '—' when missing. */
function ist(ts) {
  if (!ts) return '—';
  const d = new Date(String(ts).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? '—' : `${istFmt.format(d)} IST`;
}

const pos = (r, depth = 100) => (r == null ? `not in top ${depth}` : `#${r}`);
const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
const path = (url) => {
  if (!url) return '';
  try { const u = new URL(url); return (u.pathname + u.search) || '/'; } catch { return url; }
};

/** Loads a drill-down once, the first time `active` becomes true. */
function useLazy(fetcher, active) {
  const [state, setState] = useState({ data: null, error: null, loading: false });
  const started = useRef(false);
  useEffect(() => {
    if (!active || started.current) return;
    started.current = true;
    setState({ data: null, error: null, loading: true });
    fetcher()
      .then((data) => setState({ data, error: null, loading: false }))
      .catch((e) => { started.current = false; setState({ data: null, error: e.message, loading: false }); });
  }, [active, fetcher]);
  return state;
}

/**
 * Tooltip shown on hover AND keyboard focus (not a title attribute). The
 * bubble stays in the DOM (hidden) so aria-describedby always resolves;
 * Escape closes it. `children` is a render function given the bubble's id.
 */
export function Tip({ content, children }) {
  const id = useId();
  const ref = useRef(null);
  const [open, setOpen] = useState(false);
  const [end, setEnd] = useState(false);
  const show = () => {
    const r = ref.current?.getBoundingClientRect?.();
    setEnd(!!r && typeof window !== 'undefined' && r.left > window.innerWidth / 2);
    setOpen(true);
  };
  if (!content) return children(undefined);
  return (
    <span ref={ref} className="rk-tip" onMouseEnter={show} onMouseLeave={() => setOpen(false)} onFocus={show}
          onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget)) setOpen(false); }}
          onKeyDown={(e) => { if (e.key === 'Escape') setOpen(false); }}>
      {children(id)}
      <span role="tooltip" id={id} hidden={!open} className={`rk-tip__bubble${end ? ' rk-tip__bubble--end' : ''}`}>{content}</span>
    </span>
  );
}

function Chevron({ open }) {
  return <ChevronDown size={13} aria-hidden="true" className={`rk-chev${open ? ' is-open' : ''}`} />;
}

function Change({ value, depth }) {
  if (value == null) return null;
  if (value === 0) return <span className="cc-delta cc-delta--flat"><Minus size={11} aria-hidden="true" /> no change</span>;
  const up = value > 0;
  const Icon = up ? ArrowUpRight : ArrowDownRight;
  const big = Math.abs(value) > depth ? '' : ` ${Math.abs(value)}`;
  return <span className={`cc-delta cc-delta--${up ? 'good' : 'bad'}`}><Icon size={11} aria-hidden="true" />{up ? ' up' : ' down'}{big}</span>;
}

function Loading({ state, children }) {
  if (state.loading || (!state.data && !state.error)) return <p className="text-xs text-muted" aria-busy="true">Loading…</p>;
  if (state.error) return <p className="text-xs text-danger" role="alert">Could not load the detail: {state.error}</p>;
  return children(state.data);
}

function TipList({ title, items, more }) {
  return (
    <>
      {title && <span className="rk-tip__title">{title}</span>}
      {items.length > 0 && (
        <span className="rk-tip__list">{items.map((t, i) => <span key={i} className="rk-tip__row">{t}</span>)}</span>
      )}
      {more && <span className="rk-tip__more">{more}</span>}
    </>
  );
}

/* ────────────────────────── drill-down tables ───────────────────────── */

const FILTERS = [
  { id: 'all', label: 'All', test: () => true },
  { id: 'top10', label: 'Top 10', test: (k) => k.our_rank != null && k.our_rank <= 10 },
  { id: 'ranked', label: 'Top 100', test: (k) => k.our_rank != null },
  { id: 'unranked', label: 'Not ranking', test: (k) => k.status === 'ok' && k.our_rank == null },
  { id: 'unchecked', label: 'Not checked / failed', test: (k) => k.status !== 'ok' },
];

export function KeywordTable({ data, initialFilter = 'all' }) {
  const [filter, setFilter] = useState(initialFilter);
  const depth = data.depth || 100;
  if (!data.available && !(data.keywords || []).length) return <p className="text-xs text-muted">{data.note}</p>;
  const rows = (data.keywords || []).filter(FILTERS.find((f) => f.id === filter).test);
  const s = data.summary || {};
  return (
    <div className="rk-kw">
      <p className="text-xs text-muted">
        {plural(s.tracked, 'tracked keyword')} · {s.checked} checked on {data.latest_date}
        {s.failed ? ` · ${s.failed} failed` : ''}{s.not_checked ? ` · ${s.not_checked} not checked that day` : ''}
        {data.compared_with ? ` · previous = check on ${data.compared_with}` : ' · no earlier check to compare with'}.
        {' '}Google {data.tracker_status?.status === 'ready' ? '' : `(tracker: ${data.tracker_status?.status}) `}top {depth}, DataForSEO.
      </p>
      <div className="cc-seg rk-kw__filters" role="group" aria-label="Filter keywords">
        {FILTERS.map((f) => (
          <button key={f.id} type="button" aria-pressed={filter === f.id} className={filter === f.id ? 'is-active' : ''} onClick={() => setFilter(f.id)}>
            {f.label} ({(data.keywords || []).filter(f.test).length})
          </button>
        ))}
      </div>
      <div className="rk-table-wrap">
        <table className="rk-table">
          <caption className="sr-only">Every tracked keyword with our Google position, the previous check, the ranking URL and the best competitor</caption>
          <thead>
            <tr>
              <th scope="col">Keyword</th>
              <th scope="col">Our position</th>
              <th scope="col">Previous</th>
              <th scope="col">Ranking URL</th>
              <th scope="col">Best competitor</th>
              <th scope="col"><abbr title="Search Console impressions for this exact query">GSC impr.</abbr></th>
              <th scope="col">Last checked</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={7} className="text-muted">No keywords in this filter.</td></tr>}
            {rows.map((k) => (
              <tr key={k.id} className={k.status !== 'ok' ? 'rk-row--muted' : ''}>
                <th scope="row">
                  <span className="rk-kw__name">{k.keyword}</span>
                  <span className="rk-kw__meta">{[k.product, k.priority && `${k.priority} priority`, !k.enabled && 'paused'].filter(Boolean).join(' · ')}</span>
                </th>
                <td>
                  {k.status === 'ok' && <strong className={k.our_rank != null && k.our_rank <= 10 ? 'rk-top10' : ''}>{pos(k.our_rank, depth)}</strong>}
                  {k.status === 'failed' && <span className="text-danger rk-wrap-text">check failed{k.error ? `: ${k.error}` : ''}</span>}
                  {k.status === 'not_checked' && <span className="text-muted">not checked on {data.latest_date}</span>}
                </td>
                <td>
                  {k.previous_checked
                    ? <>{pos(k.previous_rank, depth)} <Change value={k.change} depth={depth} /></>
                    : <span className="text-muted">{data.compared_with ? `no check on ${data.compared_with}` : '—'}</span>}
                </td>
                <td className="rk-url">
                  {k.our_url ? <a href={k.our_url} target="_blank" rel="noreferrer">{path(k.our_url)} <ExternalLink size={10} aria-hidden="true" /><span className="sr-only"> (opens in a new tab)</span></a> : <span className="text-muted">—</span>}
                </td>
                <td>
                  {k.best_competitor ? (
                    <span>{k.best_competitor.name} <strong>#{k.best_competitor.rank}</strong>
                      {k.our_rank != null && <span className="rk-kw__meta">{k.best_competitor.rank < k.our_rank ? 'ahead of us' : 'behind us'}</span>}
                    </span>
                  ) : <span className="text-muted">{k.status === 'ok' ? `none in top ${depth}` : '—'}</span>}
                </td>
                <td>{k.search_console ? <>{num(k.search_console.impressions)}<span className="rk-kw__meta">{num(k.search_console.clicks)} clicks{k.search_console.avg_position != null ? ` · pos ${k.search_console.avg_position}` : ''}</span></> : <span className="text-muted">—</span>}</td>
                <td className="rk-nowrap">{ist(k.last_checked_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-muted mt-1">
        {data.volume_note}{data.search_console_window ? ` (${data.search_console_window.from} → ${data.search_console_window.to})` : ' No Search Console data ingested yet.'}
      </p>
    </div>
  );
}

function PlacementTable({ name, rows, depth }) {
  if (!rows.length) return <p className="text-xs text-muted">{name} is not in the top {depth} for any tracked keyword on this check.</p>;
  return (
    <div className="rk-table-wrap">
      <table className="rk-table rk-table--compact">
        <caption className="sr-only">Keywords where {name} ranks, with their position and ours</caption>
        <thead><tr><th scope="col">Keyword</th><th scope="col">{name}</th><th scope="col">AICOUNTLY</th></tr></thead>
        <tbody>
          {rows.map((p) => (
            <tr key={p.keyword}>
              <th scope="row">{p.keyword}</th>
              <td>{p.url ? <a href={p.url} target="_blank" rel="noreferrer">#{p.rank}<span className="sr-only"> (opens their ranking page in a new tab)</span></a> : `#${p.rank}`}</td>
              <td>{pos(p.our_rank, depth)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function AiAnswers({ data }) {
  if (!data.available) return <p className="text-xs text-muted">{data.note}</p>;
  const t = data.totals || {};
  const skipped = (data.providers || []).filter((p) => !p.will_run);
  return (
    <div className="rk-ai">
      <p className="text-xs text-muted">
        {data.period.from} → {data.period.to} (IST): {plural(t.answers, 'answer')}{t.failed ? `, ${t.failed} failed runs` : ''}.{' '}
        {data.measured
          ? `${data.brand} was named in ${t.brand_mentions} of ${t.counted} counted answers (${pct(data.mention_rate, 0)}).`
          : `Not measured: every answer was to a question that already names ${data.brand}.`}
        {t.answers > t.counted ? ` ${t.answers - t.counted} answers to questions naming ${data.brand} are not counted for it.` : ''}
      </p>
      {skipped.length > 0 && (
        <p className="text-xs text-muted">Not asked: {skipped.map((p) => `${p.label || p.key} (${p.reason})`).join('; ')}</p>
      )}
      <ul className="rk-ai__list">
        {(data.prompts || []).map((p) => (
          <li key={p.prompt_id} className="rk-ai__prompt">
            <div className="rk-ai__q">“{p.question}”</div>
            <div className="rk-kw__meta">{[p.name, p.market].filter(Boolean).join(' · ')}</div>
            <ul className="rk-ai__providers">
              {p.providers.map((pp) => (
                <li key={pp.provider}>
                  <span className="rk-ai__prov">{pp.provider}{pp.model ? ` (${pp.model})` : ''}</span>
                  <span>
                    {plural(pp.answers, 'answer')}{pp.failed ? `, ${pp.failed} failed` : ''} ·{' '}
                    {pp.counted > 0
                      ? <strong className={pp.brand_mentions > 0 ? 'text-success' : ''}>{data.brand} named in {pp.brand_mentions} of {pp.counted}</strong>
                      : <span className="text-muted">question names {data.brand}, not counted</span>}
                    {' · '}{pp.competitors.length
                      ? `named: ${pp.competitors.map((c) => `${c.name} ${c.answers}×`).join(', ')}`
                      : 'no tracked competitor named'}
                    {pp.last_asked_at ? ` · last ${ist(pp.last_asked_at)}` : ''}
                  </span>
                  {pp.last_brand_excerpt && <span className="rk-ai__excerpt">“{pp.last_brand_excerpt}”</span>}
                  {pp.last_error && <span className="text-xs text-danger">Last error: {pp.last_error}</span>}
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ul>
      <p className="text-xs text-muted mt-1">{data.sample_note}</p>
    </div>
  );
}

/* ─────────────────────────────── section ─────────────────────────────── */

export function Rankings({ section }) {
  const t = section?.tracker || {};
  const v = section?.visibility || {};
  const depth = t.depth || 100;
  const [open, setOpen] = useState(null);
  const baseId = useId();
  const pid = (k) => `${baseId}-${k}`;
  const toggle = (k) => setOpen((o) => (o === k ? null : k));

  const needKeywords = open === 'keywords' || open === 'top10' || (typeof open === 'string' && open.startsWith('comp:'));
  const kw = useLazy(useCallback(() => rankingsDrilldownService.keywords(), []), needKeywords);
  const ai = useLazy(useCallback(() => rankingsDrilldownService.aiAnswers(), []), open === 'ai');

  const top10Names = (t.top10_keywords || []).map((k) => `${k.keyword} — #${k.rank}`);
  const rankedNames = (t.ranked_keywords || []).map((k) => `${k.keyword} — #${k.rank}`);
  const avgText = t.avg_rank != null ? `avg position ${t.avg_rank} over ${plural(t.avg_rank_over ?? t.ranked, 'ranked keyword')}` : 'no ranked keyword to average';
  const checkedLine = `${t.keywords_checked} of ${plural(t.tracked ?? t.keywords_checked, 'tracked keyword')} checked on ${t.latest_date}`
    + `${t.failed ? `, ${t.failed} failed` : ''}${t.not_checked ? `, ${t.not_checked} not checked` : ''}.`;

  const moversTip = (m) => `${m.keyword}: ${pos(m.previous_rank, depth)} on ${t.compared_with} → ${pos(m.rank, depth)} on ${t.latest_date}${m.url ? ` · ${path(m.url)}` : ''}`;
  const moverRow = (m, good) => (
    <li key={m.keyword}>
      <Tip content={moversTip(m)}>{(d) => (
        <span tabIndex={0} aria-describedby={d} className="rk-focusable">{m.keyword}</span>
      )}</Tip>
      <span className={`cc-delta cc-delta--${good ? 'good' : 'bad'}`}>
        {good ? <ArrowUpRight size={12} aria-hidden="true" /> : <ArrowDownRight size={12} aria-hidden="true" />} {m.previous_rank ?? `>${depth}`} → {m.rank ?? `>${depth}`}
      </span>
    </li>
  );

  const compRows = [
    { key: 'us', name: 'AICOUNTLY', top10: t.top10, avg: null, kws: t.top10_keywords || [], ours: true },
    ...(t.competitors || []).map((c) => ({ key: c.domain || c.name, name: c.name, domain: c.domain, top10: c.top10, avg: c.avg_rank, over: c.keywords, kws: c.top10_keywords || [] })),
  ];
  const maxBar = Math.max(1, ...compRows.map((r) => r.top10 || 0));

  return (
    <Section id="rankings" icon={Trophy} title="Rankings" section={section}
             subtitle={t.available ? `SEO Tracker (DataForSEO, Google top ${depth}) ${t.latest_date}${t.compared_with ? ` vs ${t.compared_with}` : ''}.` : null}>
      {section?.status === 'empty' && <div className="cc-empty">{section.note}</div>}
      {t.available && (
        <>
          <div className="cc-kpis">
            <Tip content={<TipList title={`In Google's top 10 on ${t.latest_date}:`} items={top10Names}
                                   more={`${t.top10} of ${t.keywords_checked} checked keywords (${pct(t.top10_share, 0)}). ${checkedLine}`} />}>
              {(d) => (
                <button type="button" className={`cc-kpi rk-tile${open === 'top10' ? ' is-open' : ''}`} aria-expanded={open === 'top10'}
                        aria-controls={pid('top10')} aria-describedby={d} onClick={() => toggle('top10')}>
                  <span className="cc-kpi__label">Keywords in top 10 <Chevron open={open === 'top10'} /></span>
                  <span className="cc-kpi__value">{`${t.top10}/${t.keywords_checked}`}</span>
                  <span className="cc-kpi__hint">{pct(t.top10_share, 0)} of keywords checked</span>
                </button>
              )}
            </Tip>
            <Tip content={<TipList title={`Ranked in the top ${depth} on ${t.latest_date}:`} items={rankedNames}
                                   more={`Average = mean of our position over the ${plural(t.ranked, 'keyword')} we rank for; the ${t.keywords_checked - t.ranked} checked keywords outside the top ${depth} are not in it. ${checkedLine}`} />}>
              {(d) => (
                <button type="button" className={`cc-kpi rk-tile${open === 'keywords' ? ' is-open' : ''}`} aria-expanded={open === 'keywords'}
                        aria-controls={pid('keywords')} aria-describedby={d} onClick={() => toggle('keywords')}>
                  <span className="cc-kpi__label">Ranked (top {depth}) <Chevron open={open === 'keywords'} /></span>
                  <span className="cc-kpi__value">{num(t.ranked)}</span>
                  <span className="cc-kpi__hint">{avgText}</span>
                </button>
              )}
            </Tip>
          </div>
          {(open === 'top10' || open === 'keywords') && (
            <div id={pid(open)} className="rk-panel" role="region" aria-label="Every tracked keyword">
              <Loading state={kw}>{(data) => <KeywordTable key={open} data={data} initialFilter={open === 'top10' ? 'top10' : 'all'} />}</Loading>
              <PanelLinks />
            </div>
          )}

          <div className="grid grid-2 mt-2 rk-movers">
            <div>
              <div className="cc-mini-title">Movers up</div>
              {(t.movers_up || []).length === 0 ? <p className="text-xs text-muted">No gains since {t.compared_with || 'the last check'}.</p> : (
                <ul className="cc-list">{t.movers_up.map((m) => moverRow(m, true))}</ul>
              )}
            </div>
            <div>
              <div className="cc-mini-title">Movers down</div>
              {(t.movers_down || []).length === 0 ? <p className="text-xs text-muted">No drops since {t.compared_with || 'the last check'}.</p> : (
                <ul className="cc-list">{t.movers_down.map((m) => moverRow(m, false))}</ul>
              )}
            </div>
          </div>
          {t.compared_with ? (
            <p className="text-xs text-muted">
              {t.compared != null ? `${plural(t.compared, 'keyword')} checked on both ${t.compared_with} and ${t.latest_date}: ${(t.movers_up || []).length} up, ${(t.movers_down || []).length} down, ${t.unchanged} unchanged (outside the top ${depth} on both days counts as unchanged).` : ''}
              {t.no_previous ? ` ${plural(t.no_previous, 'keyword')} had no check on ${t.compared_with}, so no change is shown for them.` : ''}
              {' '}
              <button type="button" className="link-btn rk-inline-btn" aria-expanded={open === 'keywords'} aria-controls={pid('keywords')} onClick={() => toggle('keywords')}>
                {open === 'keywords' ? 'Hide' : 'See'} every keyword
              </button>
            </p>
          ) : <p className="text-xs text-muted">Only one check so far — movement appears after the next one.</p>}

          {(t.competitors || []).length > 0 && (
            <div className="mt-2">
              <div className="cc-mini-title">Competitors on the same keywords (top-10 placements)</div>
              <ul className="rk-bars" aria-label="Top-10 placements per domain">
                {compRows.map((r) => {
                  const k = `comp:${r.key}`;
                  const isOpen = open === k;
                  const display = r.ours ? `${r.top10}` : `${r.top10}${r.avg != null ? ` · avg #${r.avg} over ${r.over} kw` : ''}`;
                  const tip = (
                    <TipList title={`${r.name} in the top 10 on ${t.latest_date}:`}
                             items={r.kws.map((x) => (r.ours ? `${x.keyword} — #${x.rank}` : `${x.keyword} — #${x.rank} (us: ${pos(x.our_rank, depth)})`))}
                             more={r.ours ? `${r.top10} of ${t.keywords_checked} checked keywords.`
                               : `${r.top10} of ${t.keywords_checked} checked keywords. ${r.avg != null ? `Avg #${r.avg} = mean of ${r.name}'s best position over the ${plural(r.over, 'keyword')} it appears for in the top ${depth}.` : ''}`} />
                  );
                  return (
                    <li key={r.key}>
                      <Tip content={tip}>{(d) => (
                        <button type="button" className={`rk-bar${isOpen ? ' is-open' : ''}${r.ours ? ' rk-bar--ours' : ''}`} aria-expanded={isOpen}
                                aria-controls={pid(k)} aria-describedby={d} onClick={() => toggle(k)}>
                          <span className="rk-bar__label"><Chevron open={isOpen} /> {r.name}</span>
                          <span className="cc-bars__track" aria-hidden="true"><span className="cc-bars__fill" style={{ width: `${Math.max(2, ((r.top10 || 0) / maxBar) * 100)}%` }} /></span>
                          <span className="cc-bars__value">{display}</span>
                        </button>
                      )}</Tip>
                      {isOpen && (
                        <div id={pid(k)} className="rk-panel rk-panel--inline" role="region" aria-label={`${r.name}: keywords and positions`}>
                          {r.ours
                            ? <PlacementTable name="AICOUNTLY" depth={depth} rows={(t.ranked_keywords || []).map((x) => ({ keyword: x.keyword, rank: x.rank, url: x.url, our_rank: x.rank }))} />
                            : (
                              <Loading state={kw}>{(data) => {
                                const c = (data.competitors || []).find((x) => x.name === r.name);
                                return <PlacementTable name={r.name} depth={depth} rows={c?.placements || []} />;
                              }}</Loading>
                            )}
                        </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            </div>
          )}
        </>
      )}
      {section && !t.available && section.status !== 'empty' && <div className="cc-empty">{t.note}</div>}

      {v.available && (
        <div className="cc-kpis mt-2">
          <Tip content={<TipList title={v.measured ? `${v.brand_mentions} of ${v.brand_answers_counted} counted answers named AICOUNTLY` : 'Not measured yet'}
                                 items={[
                                   ...(v.by_provider || []).map((p) => `${p.provider}: ${p.counted > 0 ? `${p.brand_mentions} of ${p.counted}` : 'none counted'} (${plural(p.answers, 'answer')})`),
                                   ...((v.top_competitors || []).length ? [`Most named instead: ${v.top_competitors.join(', ')}`] : []),
                                 ]}
                                 more={`${v.answers - (v.brand_answers_counted ?? v.answers) > 0 ? `${v.answers - v.brand_answers_counted} answers to questions naming AICOUNTLY are not counted. ` : ''}${v.failed_runs ? `${v.failed_runs} runs failed. ` : ''}${v.note || ''}`} />}>
            {(d) => (
              <button type="button" className={`cc-kpi rk-tile${open === 'ai' ? ' is-open' : ''}`} aria-expanded={open === 'ai'}
                      aria-controls={pid('ai')} aria-describedby={d} onClick={() => toggle('ai')}>
                <span className="cc-kpi__label">AI assistants mention us <Chevron open={open === 'ai'} /></span>
                <span className="cc-kpi__value">{v.measured === false ? 'Not measured' : pct(v.mention_rate, 0)}</span>
                <span className="cc-kpi__hint">
                  {v.measured === false
                    ? `${v.answers} answers in ${v.period?.days ?? 28} days, all to questions naming us`
                    : `${v.brand_mentions != null ? `${v.brand_mentions} of ${v.brand_answers_counted} answers · ` : ''}${v.answers} answers in ${v.period?.days ?? 28} days${v.avg_rank ? ` · avg rank ${v.avg_rank}` : ''}`}
                </span>
              </button>
            )}
          </Tip>
        </div>
      )}
      {open === 'ai' && (
        <div id={pid('ai')} className="rk-panel" role="region" aria-label="AI-visibility questions and answers">
          <Loading state={ai}>{(data) => <AiAnswers data={data} />}</Loading>
          <p className="rk-links text-xs"><Link to="/intelligence/visibility">Open AI visibility →</Link> <Link to="/intelligence/visibility/prompts">Prompt library →</Link></p>
        </div>
      )}
      {section && !v.available && section.status !== 'empty' && <p className="text-xs text-muted mt-1"><Radar size={12} aria-hidden="true" /> {v.note}</p>}
    </Section>
  );
}

function PanelLinks() {
  return (
    <p className="rk-links text-xs">
      <Link to="/seo-centre/tracker">Open SEO tracker →</Link>
      <Link to="/seo-centre/keywords">Tracked keywords →</Link>
      <Link to="/seo-centre/search">Search Console →</Link>
    </p>
  );
}
