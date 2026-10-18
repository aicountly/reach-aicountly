// THE single source of truth for the primary sidebar, the command palette
// (Ctrl/⌘-K) and the legacy-route redirects.
//
// 2026-10 IA revamp (owner request): five groups that follow how the owner
// thinks about Reach — Overview · Content · Channels · Growth · Bot & system.
// Duplicates were merged (SEO Command Centre + Blog SEO and Indexing; Lead
// Capture + Engage Lead Push + Lead Outcomes → one Leads area; Analytics +
// Daily Reports → one Analytics area; Distribution's social screen → the new
// Social & Ads hub), and screens tied to the retired human-review / in-Reach
// generation flows left the menu (their routes redirect, see LEGACY_REDIRECTS).
// Section sub-navigation stays in each section's *Layout.jsx.
//
// Adding an entry: put one object in the right group with a unique `id`, a
// `path`, an `icon` and the permission(s) in `requires` (a string, or a list
// where any one is enough). The palette picks it up automatically. Streams
// building new pages: add your line here and nothing else in the shell.
import {
  LayoutDashboard, Target, NotebookPen, BookOpen, ScrollText, MessagesSquare, Clapperboard,
  Database, Image, Library, Share2, Mail, MonitorSmartphone, Search, Eye, Lightbulb, BarChart3,
  Users, BrainCircuit, Wrench, ListOrdered, PlugZap, Globe, Activity, Cable, ShieldCheck,
  ListChecks, Settings, UserCog, Settings2, SlidersHorizontal, Zap, MessageCircleQuestion, Inbox,
} from 'lucide-react';
import { ROUTES } from './routes';
import { isBlogCommandCentreEnabled } from './blogFeatureFlags';
import { CONSOLE_WEB_URL } from '../services/consoleAuth';
import { SECTION_META, slugify } from '../pages/admin/automationSettings/model';

/**
 * @typedef {Object} NavItem
 * @property {string} id            stable id (tests, palette keys)
 * @property {string} label
 * @property {string} path          in-app route (or the external URL when `external`)
 * @property {import('react').ComponentType<any>} icon
 * @property {string|string[]} [requires] one permission, or a list where any one suffices
 * @property {boolean} [end]        exact-match active state
 * @property {string} [countKey]    key in ReachCountsContext shown as a badge
 * @property {string} [keywords]    extra words the palette matches (old names, synonyms)
 * @property {boolean} [external]   opens in a new tab (Console)
 * @property {string} [hint]        tooltip / palette subtitle
 */

/** Permission list for SEO: the SEO Command Centre absorbed Blog SEO and Indexing (blog.view). */
const SEO_ACCESS = ['seo.view', 'blog.view'];
const SOCIAL_ACCESS = ['social.view', 'distribution.read'];

/** @returns {{ id: string, title: string, items: NavItem[] }[]} */
export function buildNavSections() {
  const bcc = isBlogCommandCentreEnabled();
  return [
    {
      id: 'overview',
      title: 'Overview',
      items: [
        { id: 'command-centre', label: 'Command centre', path: ROUTES.DASHBOARD, icon: LayoutDashboard, end: true, requires: 'dashboard.view', keywords: 'dashboard home overview' },
        // Page built by the bot-plan stream (plan today / tomorrow / week / month vs achieved).
        { id: 'bot-plan', label: 'Bot plan', path: ROUTES.BOT_PLAN, icon: Target, requires: 'dashboard.view', keywords: 'plan today tomorrow week month achieved targets daily pack' },
        { id: 'bot-run-now', label: 'Run now', path: ROUTES.BOT_ACTIVITIES, icon: Zap, requires: 'dashboard.view', keywords: 'bot activities trigger routine run' },
        { id: 'bot-ask', label: 'Ask the bot', path: ROUTES.BOT_ASK, icon: MessageCircleQuestion, requires: 'dashboard.view', keywords: 'chat question assistant' },
      ],
    },
    {
      id: 'content',
      title: 'Content',
      items: [
        ...(bcc ? [{ id: 'blog', label: 'Blog', path: ROUTES.BLOG_COMMAND_CENTRE, icon: NotebookPen, requires: 'blog.view', keywords: 'blog command centre articles roadmap verification publishing posts' }] : []),
        { id: 'knowledge-base', label: 'Knowledge base', path: '/publishing/knowledge-bases', icon: BookOpen, requires: 'publishing.view', keywords: 'kb help articles' },
        { id: 'release-notes', label: 'Release notes & guides', path: '/publishing/release-notes', icon: ScrollText, requires: 'publishing.view', keywords: 'workflow guides changelog' },
        { id: 'community', label: 'Community', path: '/community', icon: MessagesSquare, requires: 'community.view', keywords: 'community q&a questions answers inbox' },
        { id: 'video', label: 'Video', path: '/video', icon: Clapperboard, requires: 'video.read', keywords: 'youtube vlog shorts pipeline' },
        { id: 'content-base', label: 'Content base', path: '/quality/content-base', icon: Database, requires: 'content.view', keywords: 'quality centre files tree' },
        { id: 'cover-gallery', label: 'Cover gallery', path: '/quality/gallery', icon: Image, requires: 'blog.view', keywords: 'covers images media quality centre' },
        { id: 'product-knowledge', label: 'Product knowledge', path: ROUTES.KNOWLEDGE, icon: Library, requires: 'knowledge.view', keywords: 'knowledge products personas claims sources brand rules grounding' },
      ],
    },
    {
      id: 'channels',
      title: 'Channels',
      items: [
        { id: 'social-ads', label: 'Social & Ads', path: ROUTES.SOCIAL_HUB, icon: Share2, requires: SOCIAL_ACCESS, keywords: 'social media facebook instagram linkedin x twitter youtube google ads meta ads accounts calendar' },
        { id: 'social-inbox', label: 'Social inbox', path: ROUTES.SOCIAL_INBOX, icon: Inbox, requires: 'social.view', keywords: 'comments messages replies dms' },
        { id: 'email-campaigns', label: 'Email & campaigns', path: '/distribution', icon: Mail, requires: 'distribution.read', keywords: 'distribution email whatsapp sms campaigns audience segments' },
        { id: 'landing-pages', label: 'Landing pages', path: ROUTES.LANDING_LIST, icon: MonitorSmartphone, requires: 'campaign.view' },
      ],
    },
    {
      id: 'growth',
      title: 'Growth',
      items: [
        { id: 'seo', label: 'SEO & Search Console', path: '/seo-centre', icon: Search, requires: SEO_ACCESS, keywords: 'seo command centre blog seo indexing search console sitemap indexnow keywords backlinks' },
        { id: 'ai-visibility', label: 'AI visibility', path: '/intelligence/visibility', icon: Eye, requires: 'visibility.read', keywords: 'chatgpt gemini perplexity mentions' },
        { id: 'insights', label: 'Insights', path: '/intelligence/insights', icon: Lightbulb, requires: 'intelligence.read', keywords: 'ai insights intelligence' },
        { id: 'analytics', label: 'Analytics & reports', path: ROUTES.ANALYTICS, icon: BarChart3, requires: 'analytics.view', keywords: 'analytics daily reports ga4 traffic' },
        { id: 'leads', label: 'Leads & Engage', path: ROUTES.LEADS, icon: Users, requires: 'lead.view', countKey: 'leads_pending_push', keywords: 'lead capture engage push outcomes' },
      ],
    },
    {
      id: 'system',
      title: 'Bot & system',
      items: [
        { id: 'ai-automation', label: 'AI & Automation', path: ROUTES.AUTOMATION_SETTINGS, icon: BrainCircuit, requires: 'settings.view', keywords: 'settings keys routines models social-api.ai' },
        { id: 'bot-mode', label: 'Bot mode', path: ROUTES.BOT_SETTINGS, icon: Wrench, requires: 'bot.configure', keywords: 'auto confirm' },
        { id: 'jobs', label: 'Jobs', path: ROUTES.JOBS, icon: ListOrdered, requires: 'job.view', keywords: 'job monitor queue failed retry' },
        { id: 'worker', label: 'Worker', path: ROUTES.WORKER_STATUS, icon: PlugZap, requires: 'job.view', keywords: 'worker status playwright' },
        { id: 'publishing-log', label: 'Publishing log', path: '/publishing/deployments', icon: Globe, requires: 'publishing.view', keywords: 'publishing deployments verifications connections' },
        { id: 'ai-providers', label: 'AI providers & usage', path: ROUTES.AI, icon: Activity, requires: 'ai.generate', keywords: 'ai control centre providers models prompts usage budgets' },
        { id: 'api-health', label: 'API health', path: ROUTES.API_HEALTH, icon: Activity, requires: 'settings.view' },
        { id: 'console-sync', label: 'Console sync', path: ROUTES.CONSOLE_SYNC, icon: Cable, requires: 'integration.view' },
        { id: 'system-readiness', label: 'System readiness', path: '/readiness', icon: ShieldCheck, requires: 'readiness.read', keywords: 'system status security disaster recovery release' },
        { id: 'audit-logs', label: 'Audit logs', path: ROUTES.AUDIT_LOGS, icon: ListChecks, requires: 'audit.view' },
        { id: 'portal-settings', label: 'Portal settings', path: ROUTES.SETTINGS, icon: Settings, requires: 'settings.view' },
        // Reach mirrors roles from Console; it does not edit them.
        { id: 'roles-users', label: 'Roles & users', path: CONSOLE_WEB_URL, external: true, icon: UserCog, requires: 'settings.view', hint: 'Assigned in Console → Roles & Access (opens Console)', keywords: 'roles users team access permissions console' },
      ],
    },
  ];
}

/**
 * Whether the current user may see an entry. `requires` may be one
 * permission or a list (any one is enough); no requirement = visible.
 *
 * @param {{ requires?: string|string[] }} item
 * @param {(perm: string) => boolean} has
 */
export function canSee(item, has) {
  const r = item?.requires;
  if (!r || (Array.isArray(r) && r.length === 0)) return true;
  return Array.isArray(r) ? r.some((p) => has(p)) : has(r);
}

/** The sidebar as this user sees it: denied items dropped, empty groups dropped. */
export function visibleNavSections(has) {
  return buildNavSections()
    .map((section) => ({ ...section, items: section.items.filter((item) => canSee(item, has)) }))
    .filter((section) => section.items.length > 0);
}

/**
 * Pages other streams of this release are building. Until their routes land,
 * App.jsx mounts ComingSoonPage at each path so a nav entry never dead-ends;
 * at merge, delete the entry here once the real <Route> exists.
 */
export const PLANNED_ROUTES = [
  { path: ROUTES.BOT_PLAN, label: 'Bot plan', description: 'What the bot plans for today, tomorrow, this week and this month, against what it achieved.' },
  { path: ROUTES.BOT_ACTIVITIES, label: 'Run now', description: 'Start a bot activity or routine on demand.' },
  { path: ROUTES.BOT_ASK, label: 'Ask the bot', description: 'Ask the bot about its work and plans.' },
  { path: ROUTES.SOCIAL_INBOX, label: 'Social inbox', description: 'Comments and messages from the connected social accounts.' },
];

/**
 * Old routes that moved or retired. Each one redirects so bookmarks and
 * links elsewhere (dashboard tiles, emails, docs) keep working; no backend
 * API was removed.
 */
export const LEGACY_REDIRECTS = [
  // Manual planning calendar (v1/calendar/items) → the publication calendar of what is actually scheduled.
  { from: ROUTES.CONTENT_CALENDAR_LEGACY, to: '/publishing/calendar', why: 'Manual content calendar retired' },
  { from: ROUTES.CONTENT_CALENDAR, to: '/publishing/calendar', why: 'Manual content calendar retired' },
  // In-Reach Daily Pack generation → routines generate content; the bot plan shows the day.
  { from: ROUTES.CONTENT_DAILY_PACK, to: ROUTES.BOT_PLAN, why: 'In-Reach Daily Pack generation retired' },
  // Manual social queue / Distribution → Social → the Social & Ads hub calendar.
  { from: ROUTES.SOCIAL_QUEUE, to: ROUTES.SOCIAL_CALENDAR, why: 'Merged into Social & Ads' },
  { from: '/distribution/social', to: ROUTES.SOCIAL_CALENDAR, why: 'Merged into Social & Ads' },
  // Duplicate timeline of /bot/reports.
  { from: ROUTES.LOCAL_BOT_REPORTS, to: ROUTES.BOT_REPORTS, why: 'Duplicate of Bot reports' },
  // Blog SEO and Indexing rendered the same pages as the SEO Command Centre (plus three "coming soon" placeholders).
  { from: ROUTES.BLOG_SEO, to: '/seo-centre/search', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BLOG_SEO_SEARCH, to: '/seo-centre/search', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BLOG_SEO_INDEXING, to: '/seo-centre/indexnow', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BLOG_SEO_SITEMAP, to: '/seo-centre/sitemap', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BLOG_SEO_INTERNAL_LINKS, to: '/seo-centre/overview', why: 'Placeholder page retired' },
  { from: ROUTES.BLOG_SEO_CANNIBALISATION, to: '/seo-centre/overview', why: 'Placeholder page retired' },
  { from: ROUTES.BLOG_SEO_TECHNICAL, to: '/seo-centre/overview', why: 'Placeholder page retired' },
  // The Blog Command Centre's old "SEO and Indexing" tab (already a redirect) now goes straight to the merged place.
  { from: ROUTES.BCC_SEO, to: '/seo-centre/search', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BCC_SEO_SEARCH, to: '/seo-centre/search', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BCC_SEO_SITEMAP, to: '/seo-centre/sitemap', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BCC_INDEXING, to: '/seo-centre/indexnow', why: 'Merged into SEO & Search Console' },
  { from: ROUTES.BCC_SEO_INTERNAL_LINKS, to: '/seo-centre/overview', why: 'Placeholder page retired' },
  { from: ROUTES.BCC_SEO_CANNIBALISATION, to: '/seo-centre/overview', why: 'Placeholder page retired' },
  { from: ROUTES.BCC_SEO_TECHNICAL, to: '/seo-centre/overview', why: 'Placeholder page retired' },
];

// ── Command palette ────────────────────────────────────────────────────────

/**
 * Secondary destinations (tabs and sub-pages) the palette can jump to, on top
 * of every sidebar entry. Grouped by the sidebar group they live under.
 */
function secondaryPages() {
  const bcc = isBlogCommandCentreEnabled();
  const blog = bcc ? [
    { label: 'Blog roadmap — topic candidates', path: ROUTES.BCC_ROADMAP_CANDIDATES, requires: 'blog.view' },
    { label: 'Blog verification queue', path: ROUTES.BCC_VERIFICATION, requires: 'blog.view', keywords: 'gemini vetting' },
    { label: 'Routine articles', path: ROUTES.BCC_VERIFICATION_ROUTINE, requires: 'blog.view', keywords: 'claude routines submissions' },
    { label: 'Ready to publish', path: ROUTES.BCC_PUBLISHING_READY, requires: 'blog.view' },
    { label: 'Published blogs', path: ROUTES.BCC_PUBLISHED, requires: 'blog.view' },
    { label: 'Emergency unpublish', path: ROUTES.BCC_PUBLISHING_UNPUBLISH, requires: 'blog.view', keywords: 'takedown remove' },
    { label: 'Blog portfolio performance', path: ROUTES.BCC_ANALYTICS_PORTFOLIO, requires: 'blog.view' },
    { label: 'Blog settings', path: ROUTES.BCC_SETTINGS_PORTFOLIO, requires: 'blog.view', keywords: 'automation window publication rules risk thresholds' },
  ] : [];
  return [
    {
      group: 'Content',
      items: [
        ...blog,
        { label: 'Workflow guides', path: '/publishing/workflow-guides', requires: 'publishing.view' },
        { label: 'Publication calendar', path: '/publishing/calendar', requires: 'publishing.view', keywords: 'content calendar scheduled' },
        { label: 'All content items', path: ROUTES.CONTENT, requires: 'content.view', keywords: 'content studio library versions' },
        { label: 'Community question inbox', path: '/community/questions', requires: 'community.view' },
        { label: 'Community official answers', path: '/community/answers', requires: 'community.view' },
        { label: 'Community automated feed', path: '/community/automated', requires: 'community.view' },
        { label: 'Community moderation', path: '/community/moderation', requires: 'community.view' },
        { label: 'Video idea backlog', path: '/video/ideas', requires: 'video.read' },
        { label: 'Video projects', path: '/video/projects', requires: 'video.read' },
        { label: 'Video operations', path: '/video/operations', requires: 'video.read' },
        { label: 'Products', path: ROUTES.KNOWLEDGE_PRODUCTS, requires: 'product.view' },
        { label: 'Personas', path: ROUTES.KNOWLEDGE_PERSONAS, requires: 'persona.view' },
        { label: 'Claims', path: ROUTES.KNOWLEDGE_CLAIMS, requires: 'claim.view' },
        { label: 'Sources', path: ROUTES.KNOWLEDGE_SOURCES, requires: 'source.view' },
        { label: 'Brand rules', path: ROUTES.KNOWLEDGE_BRAND_RULES, requires: 'brand_rules.view' },
        { label: 'Topic clusters', path: ROUTES.KNOWLEDGE_CLUSTERS, requires: 'knowledge.view' },
      ],
    },
    {
      group: 'Channels',
      items: [
        { label: 'Social accounts', path: ROUTES.SOCIAL_ACCOUNTS, requires: 'distribution.read', keywords: 'social-api.ai connected readiness' },
        { label: 'Social calendar & queue', path: ROUTES.SOCIAL_CALENDAR, requires: 'social.view', keywords: 'scheduled posted failed retry youtube shorts' },
        { label: 'Social performance', path: ROUTES.SOCIAL_PERFORMANCE, requires: 'social.view' },
        { label: 'Ads', path: ROUTES.SOCIAL_ADS, requires: SOCIAL_ACCESS, keywords: 'google ads meta ads linkedin ads paid campaigns utm register' },
        { label: 'Campaigns', path: '/distribution/campaigns', requires: 'distribution.read' },
        { label: 'Audience segments', path: '/distribution/audience/segments', requires: 'distribution.read' },
        { label: 'Consents', path: '/distribution/audience/consents', requires: 'distribution.read' },
        { label: 'Suppressions', path: '/distribution/suppressions', requires: 'distribution.read', keywords: 'unsubscribe bounce' },
        { label: 'Email dispatch', path: '/distribution/email', requires: 'distribution.read' },
        { label: 'WhatsApp dispatch', path: '/distribution/whatsapp', requires: 'distribution.read' },
        { label: 'SMS', path: '/distribution/sms', requires: 'distribution.read', keywords: 'dlt' },
        { label: 'Delivery analytics', path: '/distribution/analytics', requires: 'distribution.read' },
        { label: 'Channel readiness', path: '/distribution/readiness', requires: 'distribution.read', keywords: 'campaign readiness go no-go' },
      ],
    },
    {
      group: 'Growth',
      items: [
        { label: 'SEO tracker', path: '/seo-centre/tracker', requires: SEO_ACCESS, keywords: 'dataforseo serp rankings' },
        { label: 'Tracked keywords', path: '/seo-centre/keywords', requires: SEO_ACCESS },
        { label: 'Backlinks', path: '/seo-centre/backlinks', requires: SEO_ACCESS },
        { label: 'SEO suggestions', path: '/seo-centre/suggestions', requires: SEO_ACCESS },
        { label: 'Search Console', path: '/seo-centre/search', requires: SEO_ACCESS, keywords: 'gsc google' },
        { label: 'IndexNow & indexing', path: '/seo-centre/indexnow', requires: SEO_ACCESS },
        { label: 'Sitemap', path: '/seo-centre/sitemap', requires: SEO_ACCESS },
        { label: 'Intelligence overview', path: '/intelligence', requires: 'intelligence.read' },
        { label: 'Content performance', path: '/intelligence/content', requires: 'intelligence.read' },
        { label: 'Attribution', path: '/intelligence/attribution', requires: 'intelligence.read', keywords: 'utm' },
        { label: 'Competitors', path: '/intelligence/competitors', requires: 'intelligence.read' },
        { label: 'Intelligence connectors', path: '/intelligence/connectors', requires: 'intelligence.read', keywords: 'ga4 search console connections' },
        { label: 'AI visibility prompt library', path: '/intelligence/visibility/prompts', requires: 'visibility.read' },
        { label: 'Daily reports', path: ROUTES.REPORTS, requires: 'analytics.view', keywords: 'email report 08:00' },
        { label: 'Engage lead push', path: ROUTES.ENGAGE_PUSH, requires: 'lead.view' },
        { label: 'Lead outcomes', path: ROUTES.LEAD_OUTCOMES, requires: 'lead.view' },
      ],
    },
    {
      group: 'Bot & system',
      items: [
        { label: 'Bot reports', path: ROUTES.BOT_REPORTS, requires: 'bot.view', keywords: 'local bot reports timeline' },
        { label: 'AI prompts', path: ROUTES.AI_PROMPTS, requires: 'ai_prompt.view' },
        { label: 'AI usage', path: ROUTES.AI_USAGE, requires: 'ai.view', keywords: 'cost tokens' },
        { label: 'AI budgets', path: ROUTES.AI_BUDGETS, requires: 'ai_provider.manage' },
        { label: 'Publishing connections', path: '/publishing/connections', requires: 'publishing.view' },
        { label: 'Publication verifications', path: '/publishing/verifications', requires: 'publishing.view' },
        { label: 'Security status', path: '/readiness/security', requires: 'readiness.read' },
        { label: 'Disaster recovery', path: '/readiness/disaster-recovery', requires: 'readiness.read' },
      ],
    },
  ];
}

/**
 * Everything the palette can jump to for this user: sidebar entries, the
 * secondary pages above, and the AI & Automation sections (the same section
 * index that page's own search uses, deep-linked by `#section`).
 *
 * @param {(perm: string) => boolean} has
 * @returns {{ id: string, label: string, path: string, group: string, icon?: any, keywords?: string, hint?: string, external?: boolean }[]}
 */
export function buildPaletteEntries(has) {
  const out = [];
  for (const section of buildNavSections()) {
    for (const item of section.items) {
      if (!canSee(item, has)) continue;
      out.push({ id: `nav:${item.id}`, label: item.label, path: item.path, group: section.title, icon: item.icon, keywords: item.keywords, hint: item.hint, external: !!item.external });
    }
  }
  for (const block of secondaryPages()) {
    for (const item of block.items) {
      if (!canSee(item, has)) continue;
      out.push({ id: `page:${item.path}`, label: item.label, path: item.path, group: block.group, keywords: item.keywords });
    }
  }
  if (has('settings.view')) {
    for (const [name, meta] of Object.entries(SECTION_META)) {
      out.push({
        id: `settings:${meta.id}`,
        label: `${name} settings`,
        path: `${ROUTES.AUTOMATION_SETTINGS}#${meta.id}`,
        group: 'Settings',
        icon: meta.icon ?? Settings2,
        keywords: meta.description,
        hint: 'AI & Automation',
      });
    }
  }
  // De-duplicate by path (first wins: sidebar label beats a secondary one).
  const seen = new Set();
  return out.filter((e) => (seen.has(e.path) ? false : (seen.add(e.path), true)));
}

/**
 * Rank palette entries for a query: every word must appear in the label,
 * group, keywords or hint; label prefix > label word > elsewhere.
 * An empty query returns the entries in order.
 */
export function searchPalette(entries, query) {
  const words = String(query ?? '').toLowerCase().split(/\s+/).filter(Boolean);
  if (words.length === 0) return entries;
  const scored = [];
  entries.forEach((e, idx) => {
    const label = e.label.toLowerCase();
    const hay = `${label} ${e.group ?? ''} ${e.keywords ?? ''} ${e.hint ?? ''}`.toLowerCase();
    if (!words.every((w) => hay.includes(w))) return;
    let score = 0;
    if (label.startsWith(words[0])) score += 4;
    if (words.every((w) => label.includes(w))) score += 2;
    if (label.split(/[^a-z0-9]+/).some((t) => t.startsWith(words[0]))) score += 1;
    scored.push({ e, score, idx });
  });
  scored.sort((a, b) => b.score - a.score || a.idx - b.idx);
  return scored.map((s) => s.e);
}

/** Individual AI & Automation settings as palette entries (only offered while searching). */
export function settingEntries(rows) {
  return (Array.isArray(rows) ? rows : []).map((row) => {
    const section = SECTION_META[row.group]?.id ?? slugify(row.group ?? 'settings');
    return {
      id: `setting:${row.key}`,
      label: row.label || row.key,
      path: `${ROUTES.AUTOMATION_SETTINGS}?q=${encodeURIComponent(row.key)}#${section}`,
      group: 'Settings',
      icon: SlidersHorizontal,
      hint: `${row.group ?? 'AI & Automation'} · ${row.key}`,
      row,
    };
  });
}
