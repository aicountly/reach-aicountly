import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard, FileText, CalendarDays, Megaphone, MonitorSmartphone,
  Share2, ListOrdered, Mail, MessageCircle, TrendingUp, Sparkles,
  Paintbrush, BarChart3, Users, ArrowRightCircle, Bot, ScrollText,
  ShieldCheck, Settings, Wrench, Activity, Cable, PlugZap, ListChecks,
  BookOpen,
} from 'lucide-react';
import { ROUTES } from '../../constants/routes';
import { BotModeBadge } from '../bot/BotModeBadge';
import { ReachLogo } from '../brand/ReachLogo';
import { useReachCounts } from '../../context/ReachCountsContext';
import { usePermission } from '../../hooks/usePermission';

const NAV = [
  {
    title: 'Marketing',
    items: [
      { label: 'Dashboard',         path: ROUTES.DASHBOARD,        icon: LayoutDashboard, end: true, requires: 'dashboard.view' },
      { label: 'Blog Management',   path: ROUTES.BLOG_LIST,        icon: FileText,        countKey: 'blog', requires: 'blog.view' },
      { label: 'Content Calendar',  path: ROUTES.CONTENT_CALENDAR, icon: CalendarDays,    requires: 'blog.view' },
      { label: 'Campaigns',         path: ROUTES.CAMPAIGN_LIST,    icon: Megaphone,       requires: 'campaign.view' },
      { label: 'Landing Pages',     path: ROUTES.LANDING_LIST,     icon: MonitorSmartphone, requires: 'campaign.view' },
      { label: 'Social Planner',    path: ROUTES.SOCIAL_PLANNER,   icon: Share2,          requires: 'social.view' },
      { label: 'Social Queue',      path: ROUTES.SOCIAL_QUEUE,     icon: ListOrdered,     countKey: 'social_queue', requires: 'social.view' },
      { label: 'Email Campaigns',   path: ROUTES.EMAIL_LIST,       icon: Mail,            requires: 'email.view' },
      { label: 'WhatsApp Campaigns',path: ROUTES.WHATSAPP_LIST,    icon: MessageCircle,   requires: 'whatsapp.view' },
      { label: 'SEO Planner',       path: ROUTES.SEO_PLANS,        icon: TrendingUp,      requires: 'blog.view' },
      { label: 'Keyword Ideas',     path: ROUTES.KEYWORD_IDEAS,    icon: Sparkles,        requires: 'blog.view' },
      { label: 'Creative Briefs',   path: ROUTES.CREATIVE_BRIEFS,  icon: Paintbrush,      requires: 'campaign.view' },
      { label: 'Analytics',         path: ROUTES.ANALYTICS,        icon: BarChart3,       requires: 'analytics.view' },
      { label: 'Lead Capture',      path: ROUTES.LEADS,            icon: Users,           requires: 'lead.view' },
      { label: 'Engage Lead Push',  path: ROUTES.ENGAGE_PUSH,      icon: ArrowRightCircle, countKey: 'leads_pending_push', requires: 'lead.view' },
    ],
  },
  {
    title: 'Marketing Bot',
    items: [
      { label: 'Bot Queue',         path: ROUTES.BOT_QUEUE,   icon: Bot,        countKey: 'bot_queue_running', requires: 'bot.view' },
      { label: 'Bot Reports',       path: ROUTES.BOT_REPORTS, icon: ScrollText, requires: 'bot.view' },
      { label: 'Console Approvals', path: ROUTES.APPROVALS,   icon: ShieldCheck, countKey: 'approvals', requires: 'approval.view' },
    ],
  },
  {
    title: 'Knowledge Foundation',
    items: [
      { label: 'Knowledge Overview', path: ROUTES.KNOWLEDGE,            icon: BookOpen, end: true, requires: 'knowledge.view' },
      { label: 'Products',           path: ROUTES.KNOWLEDGE_PRODUCTS,   icon: BookOpen, requires: 'product.view' },
      { label: 'Claims',             path: ROUTES.KNOWLEDGE_CLAIMS,     icon: BookOpen, requires: 'claim.view' },
      { label: 'Brand Rules',        path: ROUTES.KNOWLEDGE_BRAND_RULES,icon: BookOpen, requires: 'brand_rules.view' },
      { label: 'Content Policies',   path: ROUTES.KNOWLEDGE_POLICIES,      icon: BookOpen, requires: 'content_policy.view' },
      { label: 'Completeness',       path: ROUTES.KNOWLEDGE_COMPLETENESS,  icon: BookOpen, requires: 'knowledge.view' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { label: 'Settings',          path: ROUTES.SETTINGS,          icon: Settings,   requires: 'settings.view' },
      { label: 'Bot Mode',          path: ROUTES.BOT_SETTINGS,      icon: Wrench,     requires: 'bot.configure' },
      { label: 'Audit Logs',        path: ROUTES.AUDIT_LOGS,        icon: ListChecks, requires: 'audit.view' },
      { label: 'API Health',        path: ROUTES.API_HEALTH,        icon: Activity,   requires: 'settings.view' },
      { label: 'Console Sync',      path: ROUTES.CONSOLE_SYNC,      icon: Cable,      requires: 'integration.view' },
      { label: 'Worker Status',     path: ROUTES.WORKER_STATUS,     icon: PlugZap,    requires: 'job.view' },
      { label: 'Job Monitor',       path: ROUTES.JOBS,              icon: ListOrdered, requires: 'job.view' },
      { label: 'Local Bot Reports', path: ROUTES.LOCAL_BOT_REPORTS, icon: ScrollText, requires: 'bot.view' },
    ],
  },
];

export function Sidebar() {
  const counts = useReachCounts();
  const { has } = usePermission();
  const visibleSections = NAV
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => !item.requires || has(item.requires)),
    }))
    .filter((section) => section.items.length > 0);
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
        {visibleSections.map((section) => (
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
