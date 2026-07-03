import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard, FileText, CalendarDays, Megaphone, MonitorSmartphone,
  Share2, ListOrdered, Mail, MessageCircle, TrendingUp, Sparkles,
  Paintbrush, BarChart3, Users, ArrowRightCircle, Bot, ScrollText,
  ShieldCheck, Settings, Wrench, Activity, Cable, PlugZap, ListChecks,
} from 'lucide-react';
import { ROUTES } from '../../constants/routes';
import { BotModeBadge } from '../bot/BotModeBadge';
import { ReachLogo } from '../brand/ReachLogo';
import { useReachCounts } from '../../context/ReachCountsContext';

const NAV = [
  {
    title: 'Marketing',
    items: [
      { label: 'Dashboard',         path: ROUTES.DASHBOARD,        icon: LayoutDashboard, end: true },
      { label: 'Blog Management',   path: ROUTES.BLOG_LIST,        icon: FileText,        countKey: 'blog' },
      { label: 'Content Calendar',  path: ROUTES.CONTENT_CALENDAR, icon: CalendarDays },
      { label: 'Campaigns',         path: ROUTES.CAMPAIGN_LIST,    icon: Megaphone },
      { label: 'Landing Pages',     path: ROUTES.LANDING_LIST,     icon: MonitorSmartphone },
      { label: 'Social Planner',    path: ROUTES.SOCIAL_PLANNER,   icon: Share2 },
      { label: 'Social Queue',      path: ROUTES.SOCIAL_QUEUE,     icon: ListOrdered,     countKey: 'social_queue' },
      { label: 'Email Campaigns',   path: ROUTES.EMAIL_LIST,       icon: Mail },
      { label: 'WhatsApp Campaigns',path: ROUTES.WHATSAPP_LIST,    icon: MessageCircle },
      { label: 'SEO Planner',       path: ROUTES.SEO_PLANS,        icon: TrendingUp },
      { label: 'Keyword Ideas',     path: ROUTES.KEYWORD_IDEAS,    icon: Sparkles },
      { label: 'Creative Briefs',   path: ROUTES.CREATIVE_BRIEFS,  icon: Paintbrush },
      { label: 'Analytics',         path: ROUTES.ANALYTICS,        icon: BarChart3 },
      { label: 'Lead Capture',      path: ROUTES.LEADS,            icon: Users },
      { label: 'Engage Lead Push',  path: ROUTES.ENGAGE_PUSH,      icon: ArrowRightCircle, countKey: 'leads_pending_push' },
    ],
  },
  {
    title: 'Marketing Bot',
    items: [
      { label: 'Bot Queue',      path: ROUTES.BOT_QUEUE,   icon: Bot,        countKey: 'bot_queue_running' },
      { label: 'Bot Reports',    path: ROUTES.BOT_REPORTS, icon: ScrollText },
      { label: 'Console Approvals', path: ROUTES.APPROVALS, icon: ShieldCheck, countKey: 'approvals' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { label: 'Settings',         path: ROUTES.SETTINGS,     icon: Settings },
      { label: 'Bot Mode',         path: ROUTES.BOT_SETTINGS, icon: Wrench },
      { label: 'Audit Logs',       path: ROUTES.AUDIT_LOGS,   icon: ListChecks },
      { label: 'API Health',       path: ROUTES.API_HEALTH,   icon: Activity },
      { label: 'Console Sync',     path: ROUTES.CONSOLE_SYNC, icon: Cable },
      { label: 'Worker Status',    path: ROUTES.WORKER_STATUS,icon: PlugZap },
      { label: 'Local Bot Reports',path: ROUTES.LOCAL_BOT_REPORTS, icon: ScrollText },
    ],
  },
];

export function Sidebar() {
  const counts = useReachCounts();
  return (
    <aside style={{
      width: 'var(--sidebar-width)', height: '100vh', position: 'fixed', top: 0, left: 0,
      zIndex: 200,
      background: 'var(--color-surface)', borderRight: '1px solid var(--color-border)',
      display: 'flex', flexDirection: 'column', overflow: 'auto',
    }}>
      <div style={{ padding: '1rem 1rem 0.75rem', borderBottom: '1px solid var(--color-border)' }}>
        <ReachLogo height={32} />
        <div className="text-xs text-muted mt-1">Superadmin</div>
        <div className="mt-2"><BotModeBadge /></div>
      </div>

      <nav style={{ flex: 1, padding: '0.6rem' }}>
        {NAV.map((section) => (
          <div key={section.title} style={{ marginBottom: '1rem' }}>
            <p style={{
              fontSize: '0.65rem', fontWeight: 700, textTransform: 'uppercase',
              letterSpacing: '0.05em', color: 'var(--color-text-muted)',
              padding: '0 0.6rem', marginBottom: '0.25rem',
            }}>
              {section.title}
            </p>
            {section.items.map((item) => (
              <NavLink
                key={item.path}
                to={item.path}
                end={!!item.end}
                style={({ isActive }) => ({
                  display: 'flex', alignItems: 'center', gap: '0.55rem',
                  padding: '0.4rem 0.6rem', borderRadius: 'var(--radius)',
                  fontSize: '0.83rem', fontWeight: isActive ? 600 : 500,
                  color: isActive ? 'var(--color-primary-hover)' : 'var(--color-text)',
                  background: isActive ? 'var(--color-primary-light)' : 'transparent',
                  textDecoration: 'none', marginBottom: '0.1rem',
                  transition: 'background var(--transition)',
                })}
              >
                <item.icon size={15} />
                <span style={{ flex: 1 }}>{item.label}</span>
                {item.countKey != null && counts[item.countKey] > 0 && (
                  <span
                    style={{
                      minWidth: '1.25rem', padding: '0 0.35rem',
                      borderRadius: '999px', fontSize: '0.65rem', fontWeight: 600,
                      lineHeight: 1.35, textAlign: 'center',
                      background: 'var(--color-primary)', color: '#fff',
                    }}
                  >
                    {counts[item.countKey]}
                  </span>
                )}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>
    </aside>
  );
}
