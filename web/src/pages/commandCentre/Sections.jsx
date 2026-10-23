import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Activity, AlertTriangle, ArrowDownRight, ArrowUpRight, CheckCircle2, CircleDashed, ExternalLink,
  Lightbulb, Minus, Radar, Sparkles, TrendingUp, Trophy, XCircle,
} from 'lucide-react';
import { api } from '../../services/api';
import { usePermission } from '../../hooks/usePermission';
import { formatDateTime } from '../../utils/formatDate';
import { useActivityDrilldown } from './useActivityDrilldown';
import { formatAge } from '../../utils/formatAge';
import { Bars, ForecastChart, Sparkline } from './Charts';
import { ago, change, inr, num, pct, WINDOWS } from './format';
import { drillHref } from '../../utils/drill';
import { ROUTES } from '../../constants/routes';
import { metricRecordsHref } from '../bot/botPlan/model';

const SEARCH = '/seo-centre/search';

/** Command-centre quota ids → the plan metric whose records they produce. */
const QUOTA_METRIC = {
  blog: 'content.blog.published',
  knowledge_base: 'content.knowledge_base.published',
  community: 'content.community.published',
  release_note: 'content.release_note.published',
  workflow_guide: 'content.workflow_guide.published',
  video_vlog: 'video.vlog.published',
  video_short: 'video.short.published',
};

/** A roadmap item's record: content items open their page; calendar entries the calendar. */
function roadmapItemHref(it) {
  if (it.source === 'calendar') return '/publishing/calendar';
  return it.id ? ROUTES.CONTENT_DETAIL.replace(':id', it.id) : null;
}

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
          <Link key={r.id} to={ROUTES.BOT_ACTIVITIES} className={`cc-pill cc-pill--${!r.enabled ? '' : r.stale ? 'warn' : 'ok'}`}
                title={r.last_at ? `Last submission ${r.last_at}` : 'No submission yet'}>
            {r.label}: {!r.enabled ? 'off' : r.last_at ? ago(r.last_at) : 'never'}
          </Link>
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

const NEGATIVE_KINDS = ['vetting_rejected', 'job_failed'];

/** "aicountly.com", "youtube.com" — names the site a public link opens. */
function hostOf(url) {
  try { return new URL(url).host.replace(/^www\./, ''); } catch { return 'live page'; }
}

/** One item behind a counter: title, IST time, detail, Reach + public links. */
function ActivityItem({ item, showLabel, itemRef }) {
  return (
    <li ref={itemRef} tabIndex={-1} className={`cc-feed__item cc-feed__item--${item.tone}`}>
      <span className="cc-feed__dot" aria-hidden="true" />
      <div className="cc-feed__main">
        <div className="cc-feed__title">{item.link ? <Link to={item.link}>{item.title}</Link> : item.title}</div>
        <div className="cc-feed__meta text-xs text-muted">
          {[showLabel ? item.label : null, item.content_type || null, item.kind === 'vetting_rejected' ? item.ref : item.detail]
            .filter(Boolean).join(' · ')}
        </div>
        {item.reasons?.length > 0 && (
          <ul className="cc-feed__reasons text-xs" aria-label="Gemini's reasons">
            {item.reasons.map((r, i) => <li key={i}>{r}</li>)}
          </ul>
        )}
        {item.public_url && (
          <a className="cc-feed__public text-xs" href={item.public_url} target="_blank" rel="noopener noreferrer">
            {hostOf(item.public_url)} <ExternalLink size={11} aria-hidden="true" /><span className="sr-only"> (opens in a new tab)</span>
          </a>
        )}
      </div>
      <time className="cc-feed__time text-xs text-muted" dateTime={item.at} title={ago(item.at)}>{formatDateTime(item.at)}</time>
    </li>
  );
}

/** Failed jobs of one type: how many, when they started and stopped, the latest error. */
function JobGroup({ group, canOpenJobs, itemRef }) {
  return (
    <li ref={itemRef} tabIndex={-1} className="cc-feed__item cc-feed__item--negative">
      <span className="cc-feed__dot" aria-hidden="true" />
      <div className="cc-feed__main">
        <div className="cc-feed__title"><code>{group.job_type}</code> · {num(group.count)} failed</div>
        <div className="cc-feed__meta cc-feed__meta--wrap text-xs text-muted">
          Latest {formatDateTime(group.latest_at)} ({ago(group.latest_at)}) · first {formatDateTime(group.first_at)} ·{' '}
          <span className={group.last_24h > 0 ? 'text-danger' : 'text-success'}>
            {group.last_24h > 0 ? `${num(group.last_24h)} in the last 24 h` : 'none in the last 24 h'}
          </span>
        </div>
        {group.latest_error && <div className="cc-feed__error text-xs">{group.latest_error}</div>}
        {canOpenJobs && (
          <div className="cc-feed__links text-xs">
            <Link to={group.link}>Open these {num(group.count)} in the Job Monitor</Link>
            <Link to={group.latest_link}>Latest job</Link>
          </div>
        )}
      </div>
    </li>
  );
}

export function ActivityFeed({ section }) {
  const { has } = usePermission();
  const [win, setWin] = useState('d7');
  const [kind, setKind] = useState('');
  const ok = section?.status === 'ok';
  const drill = useActivityDrilldown({ kind, win, refreshKey: section?.built_at ?? null, enabled: ok });

  // The drill-down answers with counters from the same moment as its list,
  // so tile and list agree even when the cached section is a minute old.
  const counters = useMemo(() => drill.counters || section?.counters || {}, [drill.counters, section?.counters]);
  const kinds = Object.entries(counters);
  const winLabel = WINDOWS.find((w) => w.id === win).label;
  const allCount = kinds.reduce((s, [, c]) => s + (c[win] || 0), 0);
  const label = kind ? counters[kind]?.label || drill.label : 'All';

  const summary = drill.total === null ? '' : drill.grouped
    ? `${num(drill.total)} failed jobs in ${num(drill.groupsTotal)} job types · last ${winLabel} · showing ${num(drill.items.length)} types`
    : `${num(drill.total)} ${drill.total === 1 ? 'item' : 'items'} · last ${winLabel} · showing ${num(drill.items.length)}`;

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
        <div className="cc-counters" role="group" aria-label="Show the items behind a count">
          <button type="button" className={`cc-counter${kind === '' ? ' is-active' : ''}`} aria-pressed={kind === ''} aria-controls="cc-activity-list" onClick={() => setKind('')}>
            <span className="cc-counter__value">{num(allCount)}</span>
            <span className="cc-counter__label">All</span>
          </button>
          {kinds.filter(([k, c]) => c.d30 > 0 || k === kind).map(([k, c]) => (
            <button key={k} type="button" className={`cc-counter${kind === k ? ' is-active' : ''}${NEGATIVE_KINDS.includes(k) ? ' cc-counter--neg' : ''}`}
                    aria-pressed={kind === k} aria-controls="cc-activity-list" onClick={() => setKind(kind === k ? '' : k)}>
              <span className="cc-counter__value">{num(c[win])}</span>
              <span className="cc-counter__label">{c.label}</span>
            </button>
          ))}
        </div>
      )}
      {ok && (
        <div id="cc-activity-list" className="cc-drill" aria-busy={drill.loading}>
          <div className="cc-drill__head">
            <h3 className="cc-drill__title">{label}</h3>
            <span className="text-xs text-muted" role="status" aria-live="polite">{summary}</span>
          </div>
          {drill.error && (
            <div className="cc-empty cc-empty--error" role="alert">
              {drill.error} <button type="button" className="btn btn-sm btn-secondary" onClick={drill.retry}>Try again</button>
            </div>
          )}
          {drill.loading && drill.items.length === 0 && <Skeleton />}
          {!drill.loading && !drill.error && drill.total === 0 && <Empty>Nothing in this window.</Empty>}
          {drill.items.length > 0 && (
            <ol className="cc-feed" aria-label={`${label}, last ${winLabel}`}>
              {drill.items.map((it, i) => {
                const ref = i === drill.focusIndex ? drill.focusRef : undefined;
                return drill.grouped
                  ? <JobGroup key={it.job_type} group={it} canOpenJobs={has('job.view')} itemRef={ref} />
                  : <ActivityItem key={`${it.kind}-${it.id}`} item={it} showLabel={!kind} itemRef={ref} />;
              })}
            </ol>
          )}
          {drill.hasMore && (
            <button type="button" className="btn btn-sm btn-secondary cc-drill__more" onClick={drill.loadMore} disabled={drill.loading}>
              {drill.loading ? 'Loading…' : `Load more (${num((drill.grouped ? drill.groupsTotal : drill.total) - drill.items.length)} left)`}
            </button>
          )}
        </div>
      )}
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
                <Kpi label="CTR" value={pct(s.current.ctr, 2)} hint={`avg position ${s.current.avg_position ?? '—'}`} to={SEARCH} />
                <Kpi label="From Reach pages" value={num(s.reach_share.clicks)}
                     hint={s.reach_share.share_of_clicks != null ? `${pct(s.reach_share.share_of_clicks)} of clicks · ${s.reach_share.pages} pages` : 'no clicks yet'}
                     to={s.reach_share.clicks > 0 ? drillHref(SEARCH, { by: 'page' }) : undefined} />
              </div>
              <Sparkline values={(s.daily || []).map((d) => d.clicks)} width={600} height={44} label={`Daily search clicks, last ${(s.daily || []).length} days`} />
              {s.low_volume && <p className="text-xs text-muted">Low volume: fewer than ~3 clicks a day, so week-to-week changes are mostly noise.</p>}
              <div className="grid grid-2 mt-2">
                <div>
                  <div className="cc-mini-title">Top queries</div>
                  <Bars label="Top queries by clicks" rows={(s.top_queries || []).map((q) => ({
                    label: q.dimension_value, value: q.clicks, display: `${q.clicks} · ${num(q.impressions)} imp`,
                    to: drillHref(SEARCH, { query: q.dimension_value, by: 'page' }),
                  }))} />
                </div>
                <div>
                  <div className="cc-mini-title">Top pages</div>
                  <Bars label="Top pages by clicks" rows={(s.top_pages || []).map((q) => ({
                    key: q.dimension_value, label: q.dimension_value.replace(/^https?:\/\/[^/]+/, '') || '/', value: q.clicks, display: `${q.clicks} · ${num(q.impressions)} imp`,
                    to: drillHref(SEARCH, { page: q.dimension_value, by: 'query' }),
                  }))} />
                </div>
              </div>
            </>
          ) : <Empty>{s.note || 'No Search Console data yet.'}</Empty>}

          <div className="cc-kpis mt-2">
            <Kpi label="Leads (28 d)" value={leads.available ? num(leads.current) : '—'} delta={leads.available ? leads.change : undefined}
                 hint={leads.available ? `${num(leads.pushed)} pushed to Engage${leads.push_problems ? ` · ${leads.push_problems} push problems` : ''}` : 'no leads captured yet'} to="/leads" />
            <Kpi label="Engage outcomes (90 d)" value={out.available ? num(out.events) : '—'}
                 hint={out.available ? `${num(out.conversions)} conversions · ${out.content_attributed} from content` : 'no outcomes received yet'} to="/leads/outcomes" />
            <Kpi label="Revenue attributed" value={out.available ? inr(out.revenue_inr) : '—'} hint={out.available ? `${out.campaign_attributed} campaign-attributed events` : ''}
                 to={out.revenue_inr > 0 ? ROUTES.LEAD_OUTCOMES : undefined} />
            {/* /social/queue only redirects (and drops the query), so link the calendar itself. */}
            <Kpi label="Social posts (28 d)" value={social.available ? num(social.posted) : '—'}
                 hint={social.available ? `${social.failed} failed · ${social.publishing} publishing` : 'nothing posted yet'}
                 to={social.posted > 0 ? drillHref(ROUTES.SOCIAL_CALENDAR, { status: 'posted' }) : ROUTES.SOCIAL_CALENDAR} />
          </div>
          {section?.ga4?.available && (
            <p className="text-xs text-muted mt-1">GA4 (28 d): {num(section.ga4.sessions)} sessions · {num(section.ga4.users)} users · {num(section.ga4.page_views)} views on Reach content.</p>
          )}
        </>
      )}
    </Section>
  );
}

/* Rankings lives in ./Rankings.jsx (tooltips + drill-downs). */

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
              <li key={`${it.source}-${it.id}`}>
                <span>{roadmapItemHref(it) ? <Link to={roadmapItemHref(it)}>{it.title}</Link> : it.title}</span>
                <span className="text-xs text-muted">{it.date} · {it.type}</span>
              </li>
            ))}</ul>
          )}
        </div>
        <div>
          <div className="cc-mini-title">Expected output (configured daily quotas)</div>
          {/* A quota drills to what it has produced (the plan metric's records). */}
          <Bars label="Daily quotas" rows={(section?.quotas || []).map((q) => ({
            key: q.id, label: q.label, value: q.per_day, display: q.enabled ? `${q.per_day}/day · ${q.next_14_days} in 14 d` : 'off',
            to: QUOTA_METRIC[q.id] ? metricRecordsHref(QUOTA_METRIC[q.id]) : undefined,
          }))} />
        </div>
      </div>
    </Section>
  );
}
