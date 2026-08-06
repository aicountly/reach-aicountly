// THE single source of truth for the primary sidebar.
//
// 2026-08 IA revamp: duplicated Marketing links (campaigns/social/email/
// WhatsApp live in Distribution), the stubbed Marketing Bot section, and the
// superseded SEO Planner / Keyword Ideas entries were removed; Quality
// Centre (cover gallery + content base) and the SEO Command Centre were
// added; the Blog Command Centre's "SEO and Indexing" tab was promoted out
// of that tab strip into the SEO block as "Blog SEO and Indexing".
// Section sub-navigation stays in each section's *Layout.jsx.
import {
  LayoutDashboard, FileText, CalendarDays, MonitorSmartphone,
  Paintbrush, BarChart3, Users, ArrowRightCircle, ScrollText,
  ShieldCheck, Settings, Wrench, Activity, Cable, PlugZap, ListChecks,
  BookOpen, PenTool, Package, BrainCircuit,
  Globe, Send, MessagesSquare, Search, NotebookPen, ListOrdered,
  Image, HelpCircle, FileSearch,
} from 'lucide-react';
import { ROUTES } from './routes';
import { isBlogCommandCentreEnabled } from './blogFeatureFlags';

export function buildNavSections() {
  return [
    {
      title: 'Marketing',
      items: [
        { label: 'Dashboard',        path: ROUTES.DASHBOARD,               icon: LayoutDashboard, end: true, requires: 'dashboard.view' },
        { label: 'Content Calendar', path: ROUTES.CONTENT_CALENDAR_LEGACY, icon: CalendarDays,    requires: 'blog.view' },
        { label: 'Landing Pages',    path: ROUTES.LANDING_LIST,            icon: MonitorSmartphone, requires: 'campaign.view' },
        { label: 'Creative Briefs',  path: ROUTES.CREATIVE_BRIEFS,         icon: Paintbrush,      requires: 'campaign.view' },
        { label: 'Analytics',        path: ROUTES.ANALYTICS,               icon: BarChart3,       requires: 'analytics.view' },
        { label: 'Lead Capture',     path: ROUTES.LEADS,                   icon: Users,           requires: 'lead.view' },
        { label: 'Engage Lead Push', path: ROUTES.ENGAGE_PUSH,             icon: ArrowRightCircle, countKey: 'leads_pending_push', requires: 'lead.view' },
      ],
    },
    ...(isBlogCommandCentreEnabled() ? [{
      title: 'Blogs',
      items: [
        { label: 'Blog Command Centre', path: ROUTES.BLOG_COMMAND_CENTRE, icon: NotebookPen, requires: 'blog.view' },
      ],
    }] : []),
    {
      title: 'Quality Centre',
      items: [
        { label: 'Cover Gallery', path: '/quality/gallery', icon: Image, requires: 'blog.view' },
        { label: 'Content Base',  path: '/blog-command-centre/roadmap/content-base', icon: BookOpen, requires: 'blog.view' },
      ],
    },
    {
      title: 'SEO',
      items: [
        { label: 'SEO Command Centre', path: '/seo-centre', icon: Search, requires: 'seo.view' },
        // Was the Blog Command Centre's "SEO and Indexing" tab; renamed so it
        // no longer reads as a rival of the SEO Command Centre above.
        { label: 'Blog SEO and Indexing', path: ROUTES.BLOG_SEO, icon: FileSearch, requires: 'blog.view' },
      ],
    },
    {
      title: 'Content Studio',
      items: [
        { label: 'All Content', path: ROUTES.CONTENT,            icon: PenTool,  end: true, requires: 'content.view' },
        { label: 'New Content', path: ROUTES.CONTENT_NEW,        icon: FileText, requires: 'content.create' },
        { label: 'Daily Pack',  path: ROUTES.CONTENT_DAILY_PACK, icon: Package,  requires: 'daily_pack.view' },
        { label: 'Calendar',    path: ROUTES.CONTENT_CALENDAR,   icon: CalendarDays, requires: 'content.view' },
      ],
    },
    {
      title: 'Channels',
      items: [
        { label: 'Distribution', path: '/distribution', icon: Send,             requires: 'distribution.read' },
        { label: 'Publishing',   path: '/publishing',   icon: Globe,            requires: 'publishing.view' },
        { label: 'Video',        path: '/video',        icon: MonitorSmartphone, requires: 'video.read' },
        { label: 'Community Q&A', path: '/community',   icon: MessagesSquare,   requires: 'community.view' },
      ],
    },
    {
      title: 'Foundations',
      items: [
        { label: 'Knowledge',         path: ROUTES.KNOWLEDGE, icon: BookOpen,     requires: 'knowledge.view' },
        { label: 'Intelligence',      path: '/intelligence',  icon: BarChart3,    requires: 'intelligence.read' },
        { label: 'AI Control Centre', path: ROUTES.AI,        icon: BrainCircuit, requires: 'ai.generate' },
      ],
    },
    {
      title: 'Administration',
      items: [
        { label: 'Console Approvals', path: ROUTES.APPROVALS,         icon: ShieldCheck, countKey: 'approvals', requires: 'approval.view' },
        { label: 'Settings',          path: ROUTES.SETTINGS,          icon: Settings,    requires: 'settings.view' },
        { label: 'Bot Mode',          path: ROUTES.BOT_SETTINGS,      icon: Wrench,      requires: 'bot.configure' },
        { label: 'Audit Logs',        path: ROUTES.AUDIT_LOGS,        icon: ListChecks,  requires: 'audit.view' },
        { label: 'API Health',        path: ROUTES.API_HEALTH,        icon: Activity,    requires: 'settings.view' },
        { label: 'Console Sync',      path: ROUTES.CONSOLE_SYNC,      icon: Cable,       requires: 'integration.view' },
        { label: 'Worker Status',     path: ROUTES.WORKER_STATUS,     icon: PlugZap,     requires: 'job.view' },
        { label: 'Job Monitor',       path: ROUTES.JOBS,              icon: ListOrdered, requires: 'job.view' },
        { label: 'System Status',     path: '/readiness',             icon: HelpCircle,  requires: 'readiness.read' },
        { label: 'Local Bot Reports', path: ROUTES.LOCAL_BOT_REPORTS, icon: ScrollText,  requires: 'bot.view' },
      ],
    },
  ];
}
