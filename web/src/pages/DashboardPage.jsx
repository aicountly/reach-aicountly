import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  FileText, Megaphone, Share2, Users, ShieldCheck, Bot, CalendarClock, ListChecks,
  LayoutGrid, RefreshCw,
} from 'lucide-react';
import { dashboardService } from '../services/dashboardService';
import { Card } from '../components/common/Card';
import { Loader } from '../components/common/Loader';
import { Alert } from '../components/common/Alert';
import { ROUTES } from '../constants/routes';
import { formatDate, formatTime } from '../utils/formatDate';

/** Tiles poll so the dashboard tracks the pipeline instead of the page load. */
const REFRESH_MS = 45_000;

const n = (value) => (typeof value === 'number' ? value : 0);

/**
 * Deep-link only when the tile has rows to show.
 *
 * A filtered list reached from a tile reading 0 lands on "nothing matches
 * this filter", which reads as a broken screen rather than an idle one. The
 * Blog Command Centre's overview cards already follow this rule; tiles now
 * do too, falling back to the unfiltered surface.
 */
const linkIfAny = (count, filtered, fallback) => (n(count) > 0 ? filtered : fallback);

function Tile({ label, value, hint, icon: Icon, to }) {
  const body = (
    <div className="stat-tile" style={{ height: '100%' }}>
      <div className="flex items-center gap-2">
        {Icon && <Icon size={16} style={{ color: 'var(--color-primary)' }} />}
        <div className="stat-tile__label">{label}</div>
      </div>
      <div className="stat-tile__value">{value ?? '—'}</div>
      {hint && <div className="stat-tile__hint">{hint}</div>}
    </div>
  );
  return to ? <Link to={to} style={{ textDecoration: 'none', color: 'inherit' }}>{body}</Link> : body;
}

export function DashboardPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const mounted = useRef(true);

  const load = useCallback(() => {
    setRefreshing(true);
    return dashboardService.summary()
      .then((d) => {
        if (!mounted.current) return;
        setData(d);
        setError(null);
      })
      .catch((e) => {
        // Keep the last good figures on screen: a failed poll must not blank
        // a dashboard that was showing real numbers a moment ago.
        if (mounted.current) setError(e.message);
      })
      .finally(() => { if (mounted.current) setRefreshing(false); });
  }, []);

  useEffect(() => {
    mounted.current = true;
    load();
    const interval = setInterval(load, REFRESH_MS);
    return () => { mounted.current = false; clearInterval(interval); };
  }, [load]);

  if (!data && error) return <Alert variant="danger">{error}</Alert>;
  if (!data) return <Loader label="Loading marketing summary…" />;

  const blog    = data.blog      || {};
  const camp    = data.campaigns || {};
  const soc     = data.social    || {};
  const leads   = data.leads     || {};
  const appr    = data.approvals || {};
  const bot     = data.bot       || {};
  const content = data.content   || {};

  const inFlight = n(blog.ideas) + n(blog.drafts) + n(blog.in_review);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Marketing dashboard</h1>
          <p className="text-sm text-muted">Overview of blog, campaigns, social, leads, bot activity.</p>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-xs text-muted">
            {data.generated_at ? `Updated ${formatTime(data.generated_at)}` : ''}
          </span>
          <button type="button" className="btn btn-sm btn-secondary" onClick={load} disabled={refreshing}>
            <RefreshCw size={14} /> {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </div>

      {error && <Alert variant="warning">Live refresh failed ({error}). Showing the last successful read.</Alert>}

      <div className="grid grid-4">
        <Tile label="Blog — in progress"      value={inFlight}           hint={`${n(blog.total)} total · ${n(blog.drafts)} drafts`} icon={FileText}  to={ROUTES.BLOG_COMMAND_CENTRE} />
        <Tile label="Blog — in review"        value={blog.in_review}     hint={`${n(blog.approved)} approved`}      icon={FileText}  to={linkIfAny(blog.in_review, ROUTES.BCC_VERIFICATION, ROUTES.BLOG_COMMAND_CENTRE)} />
        <Tile label="Blog — published"        value={blog.published}     hint={`${n(blog.pending_publishing)} pending publish`} icon={FileText} to={linkIfAny(blog.published, ROUTES.BCC_PUBLISHED, ROUTES.BLOG_COMMAND_CENTRE)} />
        <Tile label="Campaigns — running"     value={camp.running}       hint={`${n(camp.total)} total`}            icon={Megaphone} to={ROUTES.CAMPAIGN_LIST} />
        <Tile label="Social — queue"          value={soc.queue}          hint={`${n(soc.posted)} posted`}           icon={Share2}    to={ROUTES.SOCIAL_QUEUE} />
        <Tile label="Leads — pending push"    value={leads.pending_push} hint={`${n(leads.pushed)} pushed`}         icon={Users}     to={ROUTES.ENGAGE_PUSH} />
        <Tile label="Approvals — pending"     value={appr.pending}       hint={`${n(appr.total)} total`}            icon={ShieldCheck} to={ROUTES.APPROVALS} />
        <Tile label="Bot — running jobs"      value={bot.queue_running}  hint={`${n(bot.queue_queued)} queued · ${n(bot.reports_total)} reports`} icon={Bot} to={linkIfAny(bot.queue_running, `${ROUTES.JOBS}?status=processing`, ROUTES.JOBS)} />
      </div>

      <div className="grid grid-4 mt-4">
        <Tile label="Content — in review"    value={content.in_review}    hint={`${n(content.total)} items across all types`} icon={LayoutGrid}    to={ROUTES.CONTENT} />
        <Tile label="Content — scheduled"    value={content.scheduled}    hint={`${n(content.published)} published`}          icon={CalendarClock} to={ROUTES.CONTENT_CALENDAR} />
        <Tile label="Blog — needs attention" value={blog.needs_attention} hint={`${n(blog.scheduled)} scheduled`}             icon={ShieldCheck}   to={ROUTES.BLOG_COMMAND_CENTRE} />
        <Tile label="Campaigns — dispatch queue" value={camp.dispatches_queued} hint={`${n(camp.dispatches_failed)} failed`}  icon={Megaphone}     to={ROUTES.CAMPAIGN_LIST} />
      </div>

      <div className="grid grid-2 mt-4">
        <Card title={<span className="flex items-center gap-2"><CalendarClock size={14}/> Upcoming calendar</span>}>
          {(data.calendar_upcoming || []).length === 0 && <div className="text-sm text-muted">No upcoming items.</div>}
          {(data.calendar_upcoming || []).map((it) => (
            <div key={it.id} className="flex items-center justify-between" style={{ padding: '0.35rem 0', borderBottom: '1px solid var(--color-border)' }}>
              <div>
                <div className="text-sm font-semibold">{it.title || '(untitled)'}</div>
                <div className="text-xs text-muted">{formatDate(it.date)} · {it.item_kind}</div>
              </div>
              <span className="badge badge-secondary">{it.item_kind}</span>
            </div>
          ))}
        </Card>

        <Card title={<span className="flex items-center gap-2"><ListChecks size={14}/> Bot activity</span>}>
          <div className="grid grid-2">
            <Tile label="Reports total"    value={bot.reports_total}
                  to={ROUTES.BOT_REPORTS} />
            <Tile label="Reports pending"  value={bot.reports_pending}
                  to={linkIfAny(bot.reports_pending, `${ROUTES.BOT_REPORTS}?approval_status=pending`, ROUTES.BOT_REPORTS)} />
            {/* queue_* sums reach_marketing_bot_queue and reach_jobs; the Job
                Monitor is the canonical queue surface and the one still in the
                sidebar, so both land there filtered to the status counted. */}
            <Tile label="Queue completed"  value={bot.queue_completed}
                  to={linkIfAny(bot.queue_completed, `${ROUTES.JOBS}?status=completed`, ROUTES.JOBS)} />
            <Tile label="Queue failed"     value={bot.queue_failed}
                  to={linkIfAny(bot.queue_failed, `${ROUTES.JOBS}?status=failed`, ROUTES.JOBS)} />
          </div>
        </Card>
      </div>
    </div>
  );
}
