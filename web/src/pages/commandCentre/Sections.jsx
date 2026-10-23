import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Activity, AlertTriangle, ArrowDownRight, ArrowUpRight, CheckCircle2, CircleDashed, ExternalLink,
  Lightbulb, Minus, Radar, Sparkles, TrendingUp, Trophy, XCircle,
} from 'lucide-react';
import { api } from '../../services/api';
import { usePermission } from '../../hooks/usePermission';
import { formatAge } from '../../utils/formatAge';
import { Bars, ForecastChart, Sparkline } from './Charts';
import { ago, change, epochMs, inr, num, pct, WINDOWS } from './format';

/* ───────────────────────────── shared bits ───────────────────────────── */

export function Section({ id, icon: Icon, title, subtitle, section, children, actions }) {
  return (
    <section className="cc-section card" aria-labelledby={`cc-${id}-title`} data-testid={`cc-${id}`}>
      <header className="cc-section__head">
        <div className="flex items-center gap-2">
          {Icon && <Icon size={16} aria-hidden="true" className="cc-section__icon" />}
          <h2 id={`cc-${id}-title`} className="cc-section__title">{title}</h2>
        </div>
        <div className="flex items-center gap-2">
          {actions}
          {section?.built_at && <span className="text-xs text-muted">{section.cached ? 'cached · ' : ''}{ago(section.built_at)}</span>}
        </div>
      </header>
      {subtitle && <p className="cc-section__subtitle text-xs text-muted">{subtitle}</p>}
      <div className="cc-section__body">
        {!section && <Skeleton />}
        {section?.status === 'error' && <div className="cc-empty cc-empty--error" role="alert">{section.message}</div>}
        {section && section.status !== 'error' && children}
      </div>
    </section>
  );
}

export function Skeleton({ rows = 3 }) {
  return (
    <div className="cc-skeleton" aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }, (_, i) => <div key={i} className="cc-skeleton__line" style={{ width: `${90 - i * 15}%` }} />)}
    </div>
  );
}

function Empty({ children }) {
  return <div className="cc-empty">{children}</div>;
}

function Delta({ value, invert = false }) {
  const c = change(value);
  if (!c) return <span className="cc-delta cc-delta--flat">no prior period</span>;
  const good = invert ? c.dir === 'down' : c.dir === 'up';
  const Icon = c.dir === 'up' ? ArrowUpRight : c.dir === 'down' ? ArrowDownRight : Minus;
  return (
    <span className={`cc-delta cc-delta--${c.dir === 'flat' ? 'flat' : good ? 'good' : 'bad'}`}>
      <Icon size={12} aria-hidden="true" /> {c.text}
    </span>
  );
}

function Kpi({ label, value, hint, delta, to }) {
  const body = (
    <div className="cc-kpi">
      <div className="cc-kpi__label">{label}</div>
      <div className="cc-kpi__value">{value}</div>
      <div className="cc-kpi__hint">{delta !== undefined && <Delta value={delta} />} {hint}</div>
    </div>
  );
  return to ? <Link to={to} className="cc-kpi-link">{body}</Link> : body;
}

/* ───────────────────────────── health strip ──────────────────────────── */

const STATE_ICON = { ok: CheckCircle2, configured: CircleDashed, skipped: CircleDashed, not_configured: XCircle, failing: AlertTriangle };
const STATE_LABEL = { ok: 'OK', configured: 'Configured', skipped: 'Skipped', not_configured: 'Not configured', failing: 'Failing' };

export function HealthStrip({ section }) {
  if (!section) return <div className="cc-health card"><Skeleton rows={2} /></div>;
  if (section.status === 'error') return <div className="cc-health card cc-empty cc-empty--error" role="alert">{section.message}</div>;
  const q = section.queues || {};
  const f = q.filters || {};
  const jobs = (key) => (f[key] ? `/admin/jobs?${f[key]}` : '/admin/jobs');
  return (
    <div className="cc-health card" data-testid="cc-health">
      <div className="cc-health__group">
        <span className="cc-health__heading">Bot mode</span>
        <Link to="/admin/bot-mode" className={`cc-pill cc-pill--${section.bot_mode === 'auto' ? 'ok' : 'warn'}`}>
          {section.bot_mode ? section.bot_mode.toUpperCase() : '—'}
        </Link>
      </div>
      <div className="cc-health__group">
        <span className="cc-health__heading">Queues (24 h)</span>
        <Link to={jobs('completed')} className="cc-pill cc-pill--ok">{num(q.completed)} done</Link>
        <Link to={jobs('failed')} className={`cc-pill cc-pill--${q.failed ? 'bad' : 'ok'}`}>{num(q.failed)} failed</Link>
        <Link to={jobs('queued')} className="cc-pill">{num(q.queued)} queued{q.oldest_queued_secs != null ? ` · ${formatAge(q.oldest_queued_secs)}` : ''}</Link>
        {q.stalled > 0 && <Link to={jobs('stalled')} className="cc-pill cc-pill--bad">{q.stalled} stalled</Link>}
        {(q.queues || []).filter((x) => x.undrained).map((x) => (
          <Link key={x.queue} to={`/admin/jobs?${x.filters}`} className="cc-pill cc-pill--bad">{x.queue}: not drained</Link>
        ))}
      </div>
      <div className="cc-health__group">
        <span className="cc-health__heading">Integrations{section.live_checks_at ? ` · checked ${ago(section.live_checks_at)}` : ''}</span>
        {(section.integrations || []).map((i) => {
          const Icon = STATE_ICON[i.state] || CircleDashed;
          const tone = i.state === 'ok' ? 'ok' : i.state === 'failing' || i.state === 'not_configured' ? 'bad' : '';
          return (
            <Link key={i.id} to={i.settings} className={`cc-pill cc-pill--${tone}`} title={`${i.label}: ${STATE_LABEL[i.state] || i.state} — ${i.message}`}>
              <Icon size={12} aria-hidden="true" /> {i.label.split(' (')[0]}<span className="sr-only">: {STATE_LABEL[i.state] || i.state}</span>
            </Link>
          );
        })}
      </div>
      <div className="cc-health__group">
        <span className="cc-health__heading">Routines</span>
        {(section.routines || []).map((r) => (
          <span key={r.id} className={`cc-pill cc-pill--${!r.enabled ? '' : r.stale ? 'warn' : 'ok'}`}
                title={r.last_at ? `Last submission ${r.last_at}` : 'No submission yet'}>
            {r.label}: {!r.enabled ? 'off' : r.last_at ? ago(r.last_at) : 'never'}
          </span>
        ))}
      </div>
    </div>
  );
}

/* ─────────────────────────── recommendations ─────────────────────────── */

export function Recommendations({ section, onDone }) {
  const { has } = usePermission();
  const [busy, setBusy] = useState(null);
  const [result, setResult] = useState({});

  const run = async (card) => {
    const a = card.action;
    setBusy(card.id);
    try {
      await api[a.method.toLowerCase()](a.endpoint, a.body || {});
      setResult((r) => ({ ...r, [card.id]: { ok: true, text: 'Done.' } }));
      onDone?.();
    } catch (e) {
      setResult((r) => ({ ...r, [card.id]: { ok: false, text: e.message } }));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Section id="recommendations" icon={Lightbulb} title="What to do next" section={section}
             subtitle="Derived from live signals — integrations, queues, pipelines, Search Console and AI Insights.">
      {section?.status === 'empty' && <Empty>{section.note}</Empty>}
      <ul className="cc-cards">
        {(section?.cards || []).map((c) => (
          <li key={c.id} className={`cc-card cc-card--${c.severity}`}>
            <div className="cc-card__top">
              <span className={`cc-sev cc-sev--${c.severity}`}>{c.severity}</span>
              <span className="text-xs text-muted">{c.source.replace(/_/g, ' ')}</span>
            </div>
            <div className="cc-card__title">{c.title}</div>
            <div className="cc-card__detail">{c.detail}</div>
            <div className="cc-card__actions">
              {c.action && has(c.action.permission) && (
                <button type="button" className="btn btn-sm btn-primary" disabled={busy === c.id} onClick={() => run(c)}>
                  {busy === c.id ? 'Working…' : c.action.label}
                </button>
              )}
              {c.link && <Link to={c.link} className="btn btn-sm btn-secondary">Open</Link>}
              {result[c.id] && <span className={`text-xs ${result[c.id].ok ? 'text-success' : 'text-danger'}`} role="status">{result[c.id].text}</span>}
            </div>
          </li>
        ))}
      </ul>
    </Section>
  );
}

/* ─────────────────────────────── activity ────────────────────────────── */

export function ActivityFeed({ section }) {
  const [win, setWin] = useState('d7');
  const [kind, setKind] = useState('');
  const counters = section?.counters || {};
  const kinds = Object.entries(counters);

  const events = useMemo(() => {
    const ms = WINDOWS.find((w) => w.id === win).ms;
    const since = Date.now() - ms;
    return (section?.events || []).filter((e) => (!kind || e.kind === kind)
      && epochMs(e.at) >= since);
  }, [section, win, kind]);

  return (
    <Section id="activity" icon={Activity} title="What the bot did" section={section}
             actions={(
               <div className="cc-seg" role="group" aria-label="Time window">
                 {WINDOWS.map((w) => (
                   <button key={w.id} type="button" aria-pressed={win === w.id} className={win === w.id ? 'is-active' : ''} onClick={() => setWin(w.id)}>{w.label}</button>
                 ))}
               </div>
             )}>
      {section?.status === 'empty' && <Empty>{section.note}</Empty>}
      {kinds.length > 0 && (
        <div className="cc-counters" role="group" aria-label="Filter by activity">
          <button type="button" className={`cc-counter${kind === '' ? ' is-active' : ''}`} aria-pressed={kind === ''} onClick={() => setKind('')}>
            <span className="cc-counter__value">{num(kinds.reduce((s, [, c]) => s + (c[win] || 0), 0))}</span>
            <span className="cc-counter__label">All</span>
          </button>
          {kinds.filter(([, c]) => c.d30 > 0).map(([k, c]) => (
            <button key={k} type="button" className={`cc-counter${kind === k ? ' is-active' : ''}${['vetting_rejected', 'job_failed'].includes(k) ? ' cc-counter--neg' : ''}`}
                    aria-pressed={kind === k} onClick={() => setKind(kind === k ? '' : k)}>
              <span className="cc-counter__value">{num(c[win])}</span>
              <span className="cc-counter__label">{c.label}</span>
            </button>
          ))}
        </div>
      )}
      {section?.status === 'ok' && events.length === 0 && <Empty>Nothing in this window.</Empty>}
      <ol className="cc-feed">
        {events.slice(0, 30).map((e, i) => (
          <li key={`${e.kind}-${e.at}-${i}`} className={`cc-feed__item cc-feed__item--${e.tone}`}>
            <span className="cc-feed__dot" aria-hidden="true" />
            <div className="cc-feed__main">
              <div className="cc-feed__title">
                {e.link ? (e.external
                  ? <a href={e.link} target="_blank" rel="noreferrer">{e.title} <ExternalLink size={11} aria-hidden="true" /></a>
                  : <Link to={e.link}>{e.title}</Link>) : e.title}
              </div>
              <div className="cc-feed__meta text-xs text-muted">{e.label}{e.detail ? ` · ${e.detail}` : ''}</div>
            </div>
            <time className="cc-feed__time text-xs text-muted" dateTime={e.at} title={e.at}>{ago(e.at)}</time>
          </li>
        ))}
      </ol>
    </Section>
  );
}

/* ──────────────────────────────── impact ─────────────────────────────── */

export function Impact({ section }) {
  const s = section?.search || {};
  const leads = section?.leads || {};
  const out = section?.outcomes || {};
  const social = section?.social || {};
  return (
    <Section id="impact" icon={TrendingUp} title="Impact" section={section}
             subtitle={s.available ? `Search Console, site-wide, ${s.window.from} → ${s.window.to} (last 28 days of data) vs the 28 days before.` : null}>
      {section?.status === 'empty' && <Empty>{section.note}</Empty>}
      {section && section.status !== 'empty' && (
        <>
          {s.available ? (
            <>
              <div className="cc-kpis">
                <Kpi label="Search clicks" value={num(s.current.clicks)} delta={s.change.clicks} to="/intelligence/search" />
                <Kpi label="Impressions" value={num(s.current.impressions)} delta={s.change.impressions} to="/intelligence/search" />
                <Kpi label="CTR" value={pct(s.current.ctr, 2)} hint={`avg position ${s.current.avg_position ?? '—'}`} />
                <Kpi label="From Reach pages" value={num(s.reach_share.clicks)}
                     hint={s.reach_share.share_of_clicks != null ? `${pct(s.reach_share.share_of_clicks)} of clicks · ${s.reach_share.pages} pages` : 'no clicks yet'} />
              </div>
              <Sparkline values={(s.daily || []).map((d) => d.clicks)} width={600} height={44} label={`Daily search clicks, last ${(s.daily || []).length} days`} />
              {s.low_volume && <p className="text-xs text-muted">Low volume: fewer than ~3 clicks a day, so week-to-week changes are mostly noise.</p>}
              <div className="grid grid-2 mt-2">
                <div>
                  <div className="cc-mini-title">Top queries</div>
                  <Bars label="Top queries by clicks" rows={(s.top_queries || []).map((q) => ({ label: q.dimension_value, value: q.clicks, display: `${q.clicks} · ${num(q.impressions)} imp` }))} />
                </div>
                <div>
                  <div className="cc-mini-title">Top pages</div>
                  <Bars label="Top pages by clicks" rows={(s.top_pages || []).map((q) => ({ label: q.dimension_value.replace(/^https?:\/\/[^/]+/, '') || '/', value: q.clicks, display: `${q.clicks} · ${num(q.impressions)} imp` }))} />
                </div>
              </div>
            </>
          ) : <Empty>{s.note || 'No Search Console data yet.'}</Empty>}

          <div className="cc-kpis mt-2">
            <Kpi label="Leads (28 d)" value={leads.available ? num(leads.current) : '—'} delta={leads.available ? leads.change : undefined}
                 hint={leads.available ? `${num(leads.pushed)} pushed to Engage${leads.push_problems ? ` · ${leads.push_problems} push problems` : ''}` : 'no leads captured yet'} to="/leads" />
            <Kpi label="Engage outcomes (90 d)" value={out.available ? num(out.events) : '—'}
                 hint={out.available ? `${num(out.conversions)} conversions · ${out.content_attributed} from content` : 'no outcomes received yet'} to="/leads/outcomes" />
            <Kpi label="Revenue attributed" value={out.available ? inr(out.revenue_inr) : '—'} hint={out.available ? `${out.campaign_attributed} campaign-attributed events` : ''} />
            <Kpi label="Social posts (28 d)" value={social.available ? num(social.posted) : '—'}
                 hint={social.available ? `${social.failed} failed · ${social.publishing} publishing` : 'nothing posted yet'} to="/social/queue" />
          </div>
          {section?.ga4?.available && (
            <p className="text-xs text-muted mt-1">GA4 (28 d): {num(section.ga4.sessions)} sessions · {num(section.ga4.users)} users · {num(section.ga4.page_views)} views on Reach content.</p>
          )}
        </>
      )}
    </Section>
  );
}

/* ─────────────────────────────── rankings ────────────────────────────── */

export function Rankings({ section }) {
  const t = section?.tracker || {};
  const v = section?.visibility || {};
  return (
    <Section id="rankings" icon={Trophy} title="Rankings" section={section}
             subtitle={t.available ? `SEO Tracker (DataForSEO) ${t.latest_date}${t.compared_with ? ` vs ${t.compared_with}` : ''}.` : null}>
      {section?.status === 'empty' && <Empty>{section.note}</Empty>}
      {t.available && (
        <>
          <div className="cc-kpis">
            <Kpi label="Keywords in top 10" value={`${t.top10}/${t.keywords_checked}`} hint={pct(t.top10_share, 0)} to="/seo-centre/tracker" />
            <Kpi label="Ranked (top 100)" value={num(t.ranked)} hint={t.avg_rank != null ? `avg position ${t.avg_rank}` : ''} />
          </div>
          <div className="grid grid-2 mt-2">
            <div>
              <div className="cc-mini-title">Movers up</div>
              {(t.movers_up || []).length === 0 ? <p className="text-xs text-muted">No gains since the last check.</p> : (
                <ul className="cc-list">{t.movers_up.map((m) => (
                  <li key={m.keyword}><span>{m.keyword}</span><span className="cc-delta cc-delta--good"><ArrowUpRight size={12} aria-hidden="true" /> {m.previous_rank ?? '>100'} → {m.rank ?? '>100'}</span></li>
                ))}</ul>
              )}
            </div>
            <div>
              <div className="cc-mini-title">Movers down</div>
              {(t.movers_down || []).length === 0 ? <p className="text-xs text-muted">No drops since the last check.</p> : (
                <ul className="cc-list">{t.movers_down.map((m) => (
                  <li key={m.keyword}><span>{m.keyword}</span><span className="cc-delta cc-delta--bad"><ArrowDownRight size={12} aria-hidden="true" /> {m.previous_rank ?? '>100'} → {m.rank ?? '>100'}</span></li>
                ))}</ul>
              )}
            </div>
          </div>
          {(t.competitors || []).length > 0 && (
            <div className="mt-2">
              <div className="cc-mini-title">Competitors on the same keywords (top-10 placements)</div>
              <Bars label="Competitor top-10 placements"
                    rows={[{ key: 'us', label: 'AICOUNTLY', value: t.top10, display: `${t.top10}` },
                      ...t.competitors.map((c) => ({ key: c.domain, label: c.name, value: c.top10, display: `${c.top10}${c.avg_rank != null ? ` · avg ${c.avg_rank}` : ''}` }))]} />
            </div>
          )}
        </>
      )}
      {section && !t.available && section.status !== 'empty' && <Empty>{t.note}</Empty>}
      {v.available && (
        <div className="cc-kpis mt-2">
          <Kpi label="AI assistants mention us" value={pct(v.mention_rate, 0)} hint={`${v.answers} answers in ${v.period?.days ?? 28} days${v.avg_rank ? ` · avg rank ${v.avg_rank}` : ''}`} to="/intelligence/visibility" />
        </div>
      )}
      {section && !v.available && section.status !== 'empty' && <p className="text-xs text-muted mt-1"><Radar size={12} aria-hidden="true" /> {v.note}</p>}
    </Section>
  );
}

/* ────────────────────────────── projections ──────────────────────────── */

function ForecastCard({ f, history }) {
  if (!f) return null;
  if (!f.available) {
    return (
      <div className="cc-fc">
        <div className="cc-mini-title">{f.label}</div>
        <p className="text-xs text-muted">{f.reason}</p>
      </div>
    );
  }
  const trendIcon = f.trend === 'up' ? <ArrowUpRight size={12} aria-hidden="true" /> : f.trend === 'down' ? <ArrowDownRight size={12} aria-hidden="true" /> : <Minus size={12} aria-hidden="true" />;
  return (
    <div className="cc-fc">
      <div className="cc-mini-title">{f.label}</div>
      <div className="cc-fc__figure">
        <span className="cc-kpi__value">{num(f.projected.total)}</span>
        <span className="text-xs text-muted"> next {f.horizon_days} days · 80% band {num(f.projected.low)}–{num(f.projected.high)}</span>
      </div>
      <div className="text-xs">{trendIcon} {f.trend} · last {f.actual_last.days} days {num(f.actual_last.total)}{f.low_volume ? ' · low volume, wide band' : ''}</div>
      <ForecastChart history={(history || []).slice(-42)} forecast={f.series} label={`${f.label}: forecast ${f.projected.total} over ${f.horizon_days} days`} />
      <p className="cc-fc__method text-xs text-muted">{f.method}</p>
    </div>
  );
}

export function Projections({ section, impact }) {
  const fc = section?.forecasts || {};
  const daily = impact?.search?.daily || [];
  const roadmap = section?.roadmap || { items: [], by_type: {} };
  return (
    <Section id="projections" icon={Sparkles} title="Projections & roadmap" section={section} subtitle={section?.note}>
      <div className="grid grid-3">
        <ForecastCard f={fc.clicks} history={daily.map((d) => ({ date: d.date, value: d.clicks }))} />
        <ForecastCard f={fc.impressions} history={daily.map((d) => ({ date: d.date, value: d.impressions }))} />
        <ForecastCard f={fc.leads} history={[]} />
      </div>
      <div className="grid grid-2 mt-2">
        <div>
          <div className="cc-mini-title">Scheduled in the next {roadmap.days ?? 14} days ({roadmap.total ?? 0})</div>
          {(roadmap.items || []).length === 0 ? <p className="text-xs text-muted">Nothing dated yet — routine content publishes as it is vetted, without a calendar date.</p> : (
            <ul className="cc-list">{roadmap.items.slice(0, 8).map((it) => (
              <li key={`${it.source}-${it.id}`}><span>{it.title}</span><span className="text-xs text-muted">{it.date} · {it.type}</span></li>
            ))}</ul>
          )}
        </div>
        <div>
          <div className="cc-mini-title">Expected output (configured daily quotas)</div>
          <Bars label="Daily quotas" rows={(section?.quotas || []).map((q) => ({ key: q.id, label: q.label, value: q.per_day, display: q.enabled ? `${q.per_day}/day · ${q.next_14_days} in 14 d` : 'off' }))} />
        </div>
      </div>
    </Section>
  );
}
