import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard, FileText, CalendarDays, Megaphone, MonitorSmartphone,
  Share2, ListOrdered, Mail, MessageCircle, TrendingUp, Sparkles,
  Paintbrush, BarChart3, Users, ArrowRightCircle, Bot, ScrollText,
  ShieldCheck, Settings, Wrench, Activity, Cable, PlugZap, ListChecks,
  BookOpen, PenTool, Package, BrainCircuit,
  Globe, Send,
  MessagesSquare,
  Search,
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
      { label: 'Content Calendar',  path: ROUTES.CONTENT_CALENDAR_LEGACY, icon: CalendarDays,    requires: 'blog.view' },
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
    title: 'Content Studio',
    items: [
      { label: 'All Content',      path: ROUTES.CONTENT,            icon: PenTool,  end: true, requires: 'content.view' },
      { label: 'New Content',      path: ROUTES.CONTENT_NEW,        icon: FileText, requires: 'content.create' },
      { label: 'Daily Pack',       path: ROUTES.CONTENT_DAILY_PACK, icon: Package,  requires: 'daily_pack.view' },
      { label: 'Calendar',         path: ROUTES.CONTENT_CALENDAR,   icon: CalendarDays, requires: 'content.view' },
    ],
  },
  /* Sections with horizontal sub-nav: sidebar lists the section root only. */
  {
    title: 'Knowledge Foundation',
    items: [
      { label: 'Knowledge', path: ROUTES.KNOWLEDGE, icon: BookOpen, requires: 'knowledge.view' },
    ],
  },
  {
    title: 'Publishing',
    items: [
      { label: 'Publishing', path: '/publishing', icon: Globe, requires: 'publishing.view' },
    ],
  },
  {
    title: 'Distribution',
    items: [
      { label: 'Distribution', path: '/distribution', icon: Send, requires: 'distribution.read' },
    ],
  },
  {
    title: 'Video Automation',
    items: [
      { label: 'Video Automation', path: '/video', icon: MonitorSmartphone, requires: 'video.read' },
    ],
  },
  {
    title: 'Community Q&A',
    items: [
      { label: 'Community Q&A', path: '/community', icon: MessagesSquare, requires: 'community.view' },
    ],
  },
  {
    title: 'AI Control Centre',
    items: [
      { label: 'AI Control Centre', path: ROUTES.AI, icon: BrainCircuit, requires: 'ai.generate' },
    ],
  },
  {
    title: 'Intelligence',
    items: [
      { label: 'Intelligence', path: '/intelligence', icon: Search, requires: 'intelligence.read' },
    ],
  },
  {
    title: 'Product Readiness',
    items: [
      { label: 'Product Readiness', path: '/readiness', icon: LayoutDashboard, requires: 'readiness.read' },
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
    <aside className="reach-sidebar" aria-label="Primary">
      <div className="reach-sidebar__brand">
        <ReachLogo height={32} />
        <div className="text-xs text-muted mt-1">Superadmin</div>
        <div className="mt-2"><BotModeBadge /></div>
      </div>

      <nav className="reach-sidebar__nav">
        {visibleSections.map((section) => (
          <div key={section.title} className="reach-sidebar__section">
            <p className="reach-sidebar__section-title">{section.title}</p>
            {section.items.map((item) => (
              <NavLink
                key={item.path}
                to={item.path}
                end={!!item.end}
                className={({ isActive }) =>
                  `reach-sidebar__link${isActive ? ' reach-sidebar__link--active' : ''}`
                }
              >
                <item.icon size={15} style={{ flexShrink: 0 }} aria-hidden="true" />
                <span className="reach-sidebar__link-label">{item.label}</span>
                {item.countKey != null && counts[item.countKey] > 0 && (
                  <span className="reach-sidebar__count">
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
