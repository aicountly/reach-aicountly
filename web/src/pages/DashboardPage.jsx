import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  FileText, Megaphone, Share2, Users, ShieldCheck, Bot, CalendarClock, ListChecks,
} from 'lucide-react';
import { dashboardService } from '../services/dashboardService';
import { Card } from '../components/common/Card';
import { Loader } from '../components/common/Loader';
import { Alert } from '../components/common/Alert';
import { ROUTES } from '../constants/routes';

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

  useEffect(() => {
    dashboardService.summary().then(setData).catch((e) => setError(e.message));
  }, []);

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!data)  return <Loader label="Loading marketing summary…" />;

  const blog  = data.blog     || {};
  const camp  = data.campaigns|| {};
  const soc   = data.social   || {};
  const leads = data.leads    || {};
  const appr  = data.approvals|| {};
  const bot   = data.bot      || {};

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Marketing dashboard</h1>
          <p className="text-sm text-muted">Overview of blog, campaigns, social, leads, bot activity.</p>
        </div>
      </div>

      <div className="grid grid-4">
        <Tile label="Blog — drafts"           value={blog.drafts}        hint={`${blog.total || 0} total posts`}    icon={FileText}  to={ROUTES.BLOG_LIST} />
        <Tile label="Blog — in review"        value={blog.in_review}     hint={`${blog.approved || 0} approved`}    icon={FileText}  to={ROUTES.BLOG_LIST} />
        <Tile label="Blog — published"        value={blog.published}     hint={`${blog.pending_publishing || 0} pending publish`} icon={FileText} to={ROUTES.BLOG_LIST} />
        <Tile label="Campaigns — running"     value={camp.running}       hint={`${camp.total || 0} total`}          icon={Megaphone} to={ROUTES.CAMPAIGN_LIST} />
        <Tile label="Social — queue"          value={soc.queue}          hint={`${soc.posted || 0} posted`}         icon={Share2}    to={ROUTES.SOCIAL_QUEUE} />
        <Tile label="Leads — pending push"    value={leads.pending_push} hint={`${leads.pushed || 0} pushed`}       icon={Users}     to={ROUTES.ENGAGE_PUSH} />
        <Tile label="Approvals — pending"     value={appr.pending}       hint={`${appr.total || 0} total`}          icon={ShieldCheck} to={ROUTES.APPROVALS} />
        <Tile label="Bot — running jobs"      value={bot.queue_running}  hint={`${bot.reports_total || 0} reports`} icon={Bot}       to={ROUTES.BOT_QUEUE} />
      </div>

      <div className="grid grid-2 mt-4">
        <Card title={<span className="flex items-center gap-2"><CalendarClock size={14}/> Upcoming calendar</span>}>
          {(data.calendar_upcoming || []).length === 0 && <div className="text-sm text-muted">No upcoming items.</div>}
          {(data.calendar_upcoming || []).map((it) => (
            <div key={it.id} className="flex items-center justify-between" style={{ padding: '0.35rem 0', borderBottom: '1px solid var(--color-border)' }}>
              <div>
                <div className="text-sm font-semibold">{it.title || '(untitled)'}</div>
                <div className="text-xs text-muted">{it.date} · {it.item_kind}</div>
              </div>
              <span className="badge badge-secondary">{it.item_kind}</span>
            </div>
          ))}
        </Card>

        <Card title={<span className="flex items-center gap-2"><ListChecks size={14}/> Bot activity</span>}>
          <div className="grid grid-2">
            <Tile label="Reports total"    value={bot.reports_total} />
            <Tile label="Reports pending"  value={bot.reports_pending} />
            <Tile label="Queue completed"  value={bot.queue_completed} />
            <Tile label="Queue failed"     value={bot.queue_failed} />
          </div>
        </Card>
      </div>
    </div>
  );
}
