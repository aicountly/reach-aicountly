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
import { formatAge } from '../utils/formatAge';
import { drillHref } from '../utils/drill';

/** Tiles poll so the dashboard tracks the pipeline instead of the page load. */
const REFRESH_MS = 45_000;

const n = (value) => (typeof value === 'number' ? value : 0);

/** Distribution → Orchestration lists the campaign dispatch rows (no ROUTES constant). */
const ORCHESTRATION = '/distribution/orchestration';


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

export function DashboardPage({ embedded = false }) {
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
  const content = data.content   || {};

  const inFlight = n(blog.ideas) + n(blog.drafts) + n(blog.in_review);
  const jobs     = data.jobs || {};
  const jf       = jobs.filters || {};
  const jobsLink = (figure) => (jf[figure] ? `${ROUTES.JOBS}?${jf[figure]}` : ROUTES.JOBS);
  const overdue  = data.calendar_overdue || { count: 0, items: [] };
  const SCHEDULED_STATUSES = 'scheduled,ready_for_publication';
  // DashboardSummaryService IN_REVIEW — the same states content.in_review counts.
  const IN_REVIEW_STATUSES = 'validation_pending,review_pending,seo_review,internal_review';

  return (
    <div>
      {!embedded && (
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
      )}

      {error && <Alert variant="warning">Live refresh failed ({error}). Showing the last successful read.</Alert>}

      <div className="grid grid-4">
        <Tile label="Blog — in progress"      value={inFlight}           hint={`${n(blog.total)} total · ${n(blog.drafts)} drafts`} icon={FileText}  to={ROUTES.BLOG_COMMAND_CENTRE} />
        <Tile label="Blog — in review"        value={blog.in_review}     hint={`${n(blog.approved)} approved`}      icon={FileText}  to={linkIfAny(blog.in_review, ROUTES.BCC_VERIFICATION, ROUTES.BLOG_COMMAND_CENTRE)} />
        <Tile label="Blog — published"        value={blog.published}     hint={`${n(blog.pending_publishing)} pending publish`} icon={FileText} to={linkIfAny(blog.published, ROUTES.BCC_PUBLISHED, ROUTES.BLOG_COMMAND_CENTRE)} />
        <Tile label="Campaigns — running"     value={camp.running}       hint={`${n(camp.total)} total`}            icon={Megaphone} to={linkIfAny(camp.running, `${ROUTES.CAMPAIGN_LIST}?status=running`, ROUTES.CAMPAIGN_LIST)} />
        <Tile label="Social — queue"          value={soc.queue}          hint={`${n(soc.posted)} posted · ${n(soc.publishing)} publishing`} icon={Share2} to={linkIfAny(soc.queue, drillHref(ROUTES.SOCIAL_CALENDAR, { status: 'upcoming' }), ROUTES.SOCIAL_CALENDAR)} />
        <Tile label="Leads — pending push"    value={leads.pending_push} hint={`${n(leads.pushed)} pushed`}         icon={Users}     to={linkIfAny(leads.pending_push, `${ROUTES.ENGAGE_PUSH}?status=pending_push`, ROUTES.ENGAGE_PUSH)} />
        <Tile label="Approvals — pending"     value={appr.queue}         hint={`${n(appr.pending)} stage sign-offs open`} icon={ShieldCheck} to={linkIfAny(appr.queue, drillHref(ROUTES.APPROVALS, { area: 'ready_for_approval' }), ROUTES.APPROVALS)} />
        <Tile label="Bot — running jobs"      value={jobs.running}       hint={`${n(jobs.queued)} queued · ${n(jobs.stalled)} stalled`} icon={Bot} to={linkIfAny(jobs.running, jobsLink('running'), ROUTES.JOBS)} />
      </div>

      <div className="grid grid-4 mt-4">
        <Tile label="Content — in review"    value={content.in_review}    hint={`${n(content.total)} items across all types`} icon={LayoutGrid}    to={linkIfAny(content.in_review, drillHref(ROUTES.CONTENT, { workflow_status: IN_REVIEW_STATUSES }), ROUTES.CONTENT)} />
        <Tile label="Content — scheduled"    value={content.scheduled}    hint={`${n(content.scheduled) - n(overdue.count)} upcoming · ${n(overdue.count)} overdue or undated`} icon={CalendarClock} to={linkIfAny(content.scheduled, `${ROUTES.CONTENT}?workflow_status=${SCHEDULED_STATUSES}`, ROUTES.CONTENT)} />
        <Tile label="Blog — needs attention" value={blog.needs_attention} hint={`${n(blog.pending_publishing)} publishing · ${n(blog.scheduled)} scheduled`} icon={ShieldCheck} to={linkIfAny(blog.needs_attention, `${ROUTES.CONTENT}?content_type=blog&workflow_status=failed,blocked,rejected`, ROUTES.BLOG_COMMAND_CENTRE)} />
        <Tile label="Campaigns — dispatch queue" value={camp.dispatches_queued} hint={`${n(camp.dispatches_failed)} failed`}  icon={Megaphone}     to={linkIfAny(camp.dispatches_queued, drillHref(ORCHESTRATION, { status: 'queued' }), ORCHESTRATION)} />
      </div>

      <div className="grid grid-2 mt-4">
        <Card title={<span className="flex items-center gap-2"><CalendarClock size={14}/> Upcoming calendar</span>}>
          {(data.calendar_upcoming || []).length === 0 && <div className="text-sm text-muted">No upcoming items.</div>}
          {(data.calendar_upcoming || []).map((it) => (
            <div key={it.id} className="flex items-center justify-between" style={{ padding: '0.35rem 0', borderBottom: '1px solid var(--color-border)' }}>
              <div>
                <div className="text-sm font-semibold">
                  {/* Legacy editorial-calendar rows have no content item to open. */}
                  {it.content_item_id
                    ? <Link to={ROUTES.CONTENT_DETAIL.replace(':id', it.content_item_id)} style={{ color: 'inherit' }}>{it.title || '(untitled)'}</Link>
                    : (it.title || '(untitled)')}
                </div>
                <div className="text-xs text-muted">{formatDate(it.date)} · {it.item_kind}</div>
              </div>
              <span className="badge badge-secondary">{it.item_kind}</span>
            </div>
          ))}
          {n(overdue.count) > 0 && (
            <div className="mt-2" data-testid="calendar-overdue">
              <div className="text-xs font-semibold" style={{ color: 'var(--color-warning)' }}>
                {overdue.count} scheduled item{overdue.count === 1 ? '' : 's'} with no future date
              </div>
              {(overdue.items || []).map((it) => (
                <Link key={it.id} to={ROUTES.CONTENT_DETAIL.replace(':id', it.id)} className="text-xs" style={{ display: 'block', padding: '0.15rem 0' }}>
                  {it.title || '(untitled)'} — {it.reason === 'no_date' ? 'no publish date' : `was due ${formatDate(it.date)}`} · {it.workflow_status.replace(/_/g, ' ')}
                </Link>
              ))}
            </div>
          )}
        </Card>

        <Card title={<span className="flex items-center gap-2"><ListChecks size={14}/> Job queue — last {jobs.window_hours || 24} h</span>}>
          {/* Windows and live states, not lifetime totals; every figure opens
              the Job Monitor with the same predicates the API counted. */}
          <div className="grid grid-2">
            <Tile label="Completed (24 h)" value={jobs.completed} to={linkIfAny(jobs.completed, jobsLink('completed'), ROUTES.JOBS)} />
            <Tile label="Failed (24 h)"    value={jobs.failed}    hint={`${n(jobs.retrying)} retrying · ${n(jobs.skipped)} skipped (not configured)`}
                  to={linkIfAny(jobs.failed, jobsLink('failed'), ROUTES.JOBS)} />
            <Tile label="Queued now"       value={jobs.queued}    hint={jobs.oldest_queued_secs != null ? `oldest waiting ${formatAge(jobs.oldest_queued_secs)} · ${n(jobs.scheduled)} later` : `${n(jobs.scheduled)} scheduled for later`}
                  to={linkIfAny(jobs.queued, jobsLink('queued'), ROUTES.JOBS)} />
            <Tile label="Stalled"          value={jobs.stalled}   hint="lease expired — worker died mid-job"
                  to={linkIfAny(jobs.stalled, jobsLink('stalled'), ROUTES.JOBS)} />
          </div>
          {(jobs.queues || []).filter((q) => q.undrained).map((q) => (
            <Alert key={q.queue} variant="warning">
              Queue <code>{q.queue}</code> has {q.queued} job(s) waiting {formatAge(q.oldest_queued_secs)} and nothing finished on it in the last hour — is it in the worker&apos;s <code>--queue=</code> list?
            </Alert>
          ))}
          {(jobs.failing_types || []).length > 0 && (
            <div className="mt-2">
              <div className="text-xs font-semibold text-muted">Failing job types (24 h)</div>
              {jobs.failing_types.map((t) => (
                <Link key={t.job_type} to={`${ROUTES.JOBS}?${t.filters}`} style={{ display: 'block', padding: '0.3rem 0', borderBottom: '1px solid var(--color-border)', color: 'inherit' }}>
                  <div className="flex items-center justify-between">
                    <code className="text-xs">{t.job_type}</code>
                    <span className="text-xs">{t.failed} failed{t.retrying ? ` · ${t.retrying} retrying` : ''}</span>
                  </div>
                  {t.last_error && <div className="text-xs text-danger" title={t.last_error}>{t.last_error.length > 140 ? `${t.last_error.slice(0, 140)}…` : t.last_error}</div>}
                </Link>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
