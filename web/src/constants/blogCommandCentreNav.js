import { ROUTES } from './routes';

/**
 * Nine-section Blog Command Centre navigation tree.
 * Each leaf declares how the route is fulfilled (NEW, SHARED, DEEP_LINK, SCAFFOLD, etc.).
 */
export const BCC_SECTIONS = [
  {
    id: 'overview',
    label: 'Overview',
    path: ROUTES.BLOG_COMMAND_CENTRE,
    end: true,
    leaves: [
      {
        label: 'Overview',
        path: ROUTES.BLOG_COMMAND_CENTRE,
        treatment: 'NEW',
        end: true,
      },
    ],
  },
  {
    id: 'roadmap',
    label: 'Roadmap',
    path: ROUTES.BCC_ROADMAP,
    leaves: [
      { label: 'Content Base', path: `${ROUTES.BCC_ROADMAP}/content-base`, treatment: 'FULL' },
      { label: 'Topic Candidates', path: ROUTES.BCC_ROADMAP_CANDIDATES, treatment: 'FULL' },
      { label: 'Scored Roadmap', path: ROUTES.BCC_ROADMAP_SCORED, treatment: 'FULL' },
      { label: 'Content Gaps', path: ROUTES.BCC_ROADMAP_GAPS, treatment: 'SCAFFOLD' },
      { label: 'Refresh Recommendations', path: ROUTES.BCC_ROADMAP_REFRESH, treatment: 'SCAFFOLD' },
      { label: 'Optimizer History', path: ROUTES.BCC_ROADMAP_OPTIMIZER, treatment: 'FULL' },
      {
        label: 'Topic Clusters',
        path: ROUTES.BCC_ROADMAP_CLUSTERS,
        treatment: 'DEEP_LINK',
        targetPath: ROUTES.KNOWLEDGE_CLUSTERS,
      },
    ],
  },
  {
    id: 'pipeline',
    label: 'Content Pipeline',
    path: ROUTES.BCC_PIPELINE,
    leaves: [
      { label: 'Briefs', path: ROUTES.BCC_PIPELINE_BRIEFS, treatment: 'SHARED', embed: 'content-briefs' },
      { label: 'Drafts', path: ROUTES.BCC_PIPELINE_DRAFTS, treatment: 'SHARED', embed: 'content-drafts' },
      { label: 'Versions', path: ROUTES.BCC_PIPELINE_VERSIONS, treatment: 'SHARED', embed: 'content-versions' },
      { label: 'Editorial Review', path: ROUTES.BCC_PIPELINE_EDITORIAL, treatment: 'SHARED', embed: 'content-editorial' },
      { label: 'Research', path: ROUTES.BCC_PIPELINE_RESEARCH, treatment: 'SCAFFOLD' },
    ],
  },
  {
    id: 'verification',
    label: 'Verification and Approvals',
    path: ROUTES.BCC_VERIFICATION,
    leaves: [
      {
        label: 'Verification Queue',
        path: ROUTES.BCC_VERIFICATION,
        treatment: 'NEW',
      },
      { label: 'Unsupported Claims', path: ROUTES.BCC_VERIFICATION_CLAIMS, treatment: 'SCAFFOLD' },
      { label: 'Source Review', path: ROUTES.BCC_VERIFICATION_SOURCES, treatment: 'SCAFFOLD' },
      { label: 'Risk Review', path: ROUTES.BCC_VERIFICATION_RISK, treatment: 'SCAFFOLD' },
      { label: 'Media and Images', path: ROUTES.BCC_VERIFICATION_MEDIA, treatment: 'SCAFFOLD' },
      {
        label: 'Human Approval',
        path: ROUTES.BCC_APPROVALS,
        treatment: 'SHARED',
        embed: 'approvals',
        requires: 'approval.view',
      },
      {
        label: 'Approval History',
        path: ROUTES.BCC_APPROVALS_HISTORY,
        treatment: 'SHARED',
        embed: 'approvals-history',
        requires: 'approval.view',
      },
    ],
  },
  {
    id: 'publishing',
    label: 'Publishing',
    path: ROUTES.BCC_PUBLISHING,
    leaves: [
      { label: 'Ready to Publish', path: ROUTES.BCC_PUBLISHING_READY, treatment: 'NEW' },
      {
        label: 'Published Blogs',
        path: ROUTES.BCC_PUBLISHED,
        treatment: 'NEW',
      },
      {
        label: 'Publishing Calendar',
        path: ROUTES.BCC_PUBLISHING_CALENDAR,
        treatment: 'SHARED',
        embed: 'calendar',
        requires: 'publishing.view',
      },
      {
        label: 'Scheduled Blogs',
        path: ROUTES.BCC_PUBLISHING_SCHEDULED,
        treatment: 'SHARED',
        embed: 'scheduled',
        requires: 'publishing.view',
      },
      {
        label: 'Publishing Attempts',
        path: ROUTES.BCC_PUBLISHING_DEPLOYMENTS,
        treatment: 'SHARED',
        embed: 'deployments',
        requires: 'publishing.view',
      },
      {
        label: 'Rollback',
        path: ROUTES.BCC_PUBLISHING_ROLLBACK,
        treatment: 'SHARED',
        embed: 'rollback',
        requires: 'publishing.view',
      },
      { label: 'Emergency Unpublish', path: ROUTES.BCC_PUBLISHING_UNPUBLISH, treatment: 'NEW' },
    ],
  },
  {
    id: 'seo',
    label: 'SEO and Indexing',
    path: ROUTES.BCC_SEO,
    leaves: [
      {
        label: 'Search Console',
        path: ROUTES.BCC_SEO_SEARCH,
        treatment: 'DEEP_LINK',
        targetPath: '/intelligence/search',
      },
      {
        label: 'Indexing Status',
        path: ROUTES.BCC_INDEXING,
        treatment: 'DEEP_LINK',
        targetPath: '/intelligence/indexnow',
        end: true,
      },
      { label: 'Internal Links', path: ROUTES.BCC_SEO_INTERNAL_LINKS, treatment: 'SCAFFOLD' },
      { label: 'Cannibalisation', path: ROUTES.BCC_SEO_CANNIBALISATION, treatment: 'SCAFFOLD' },
      {
        label: 'Sitemap',
        path: ROUTES.BCC_SEO_SITEMAP,
        treatment: 'DEEP_LINK',
        targetPath: '/intelligence/sitemaps',
      },
      { label: 'Technical SEO', path: ROUTES.BCC_SEO_TECHNICAL, treatment: 'SCAFFOLD' },
    ],
  },
  {
    id: 'analytics',
    label: 'Analytics',
    path: ROUTES.BCC_ANALYTICS,
    leaves: [
      { label: 'Portfolio Performance', path: ROUTES.BCC_ANALYTICS_PORTFOLIO, treatment: 'NEW' },
      {
        label: 'Blog Content',
        path: ROUTES.BCC_ANALYTICS_CONTENT,
        treatment: 'DEEP_LINK',
        targetPath: '/intelligence/content',
      },
      {
        label: 'Search Performance',
        path: ROUTES.BCC_ANALYTICS_SEARCH,
        treatment: 'DEEP_LINK',
        targetPath: '/intelligence/search/queries',
      },
      {
        label: 'Product Analytics',
        path: ROUTES.BCC_ANALYTICS_PRODUCT,
        treatment: 'DEEP_LINK',
        targetPath: '/intelligence/content',
      },
      {
        label: 'Lead Attribution',
        path: ROUTES.BCC_ANALYTICS_ATTRIBUTION,
        treatment: 'DEEP_LINK',
        targetPath: '/intelligence/attribution',
      },
    ],
  },
  {
    id: 'operations',
    label: 'Operations',
    path: ROUTES.BCC_OPERATIONS,
    leaves: [
      {
        label: 'Queue Monitor',
        path: ROUTES.BCC_OPERATIONS_QUEUE,
        treatment: 'SHARED',
        embed: 'queue-monitor',
        requires: 'job.view',
      },
      {
        label: 'Failed Jobs',
        path: ROUTES.BCC_OPERATIONS_FAILED,
        treatment: 'SHARED',
        embed: 'failed-jobs',
        requires: 'job.view',
      },
      {
        label: 'Cron History',
        path: ROUTES.BCC_OPERATIONS_CRON,
        treatment: 'DEEP_LINK',
        targetPath: ROUTES.WORKER_STATUS,
      },
      {
        label: 'AI Usage and Costs',
        path: ROUTES.BCC_OPERATIONS_AI_USAGE,
        treatment: 'SHARED',
        embed: 'ai-usage',
        requires: 'ai.generate',
      },
      {
        label: 'Provider Health',
        path: ROUTES.BCC_OPERATIONS_PROVIDER_HEALTH,
        treatment: 'SHARED',
        embed: 'provider-health',
        requires: 'ai.generate',
      },
      {
        label: 'Provider Routing',
        path: ROUTES.BCC_OPERATIONS_PROVIDER_ROUTING,
        treatment: 'SHARED',
        embed: 'provider-routing',
        requires: 'ai.generate',
      },
      {
        label: 'Audit Log',
        path: ROUTES.BCC_OPERATIONS_AUDIT,
        treatment: 'SHARED',
        embed: 'audit',
        requires: 'audit.view',
      },
    ],
  },
  {
    id: 'settings',
    label: 'Settings',
    path: ROUTES.BCC_SETTINGS,
    leaves: [
      { label: 'Portfolio Mix', path: ROUTES.BCC_SETTINGS_PORTFOLIO, treatment: 'NEW' },
      { label: 'Automation Window', path: ROUTES.BCC_SETTINGS_AUTOMATION_WINDOW, treatment: 'NEW' },
      { label: 'Publication Rules', path: ROUTES.BCC_SETTINGS_PUBLICATION_RULES, treatment: 'NEW' },
      { label: 'Risk Rules', path: ROUTES.BCC_SETTINGS_RISK_RULES, treatment: 'NEW' },
      {
        label: 'Provider Routing',
        path: ROUTES.BCC_SETTINGS_PROVIDER_ROUTING,
        treatment: 'DEEP_LINK',
        targetPath: ROUTES.AI_ROUTING,
      },
      { label: 'Verification Thresholds', path: ROUTES.BCC_SETTINGS_VERIFICATION_THRESHOLDS, treatment: 'NEW' },
      { label: 'Storage', path: ROUTES.BCC_SETTINGS_STORAGE, treatment: 'NEW' },
      { label: 'SEO Configuration', path: ROUTES.BCC_SETTINGS_SEO, treatment: 'NEW' },
      { label: 'Notifications', path: ROUTES.BCC_SETTINGS_NOTIFICATIONS, treatment: 'NEW' },
    ],
  },
];

/** Flat list of every leaf for route lookup (breadcrumbs, titles). */
export function getAllBccLeaves() {
  return BCC_SECTIONS.flatMap((section) =>
    section.leaves.map((leaf) => ({ ...leaf, sectionLabel: section.label })),
  );
}

export function findBccLeafByPath(pathname) {
  const leaves = getAllBccLeaves();
  const exact = leaves.find((leaf) => leaf.path === pathname);
  if (exact) return exact;
  return leaves.find((leaf) => pathname.startsWith(`${leaf.path}/`));
}

export function findBccSectionByPath(pathname) {
  if (pathname === ROUTES.BLOG_COMMAND_CENTRE) {
    return BCC_SECTIONS[0];
  }
  return BCC_SECTIONS.find(
    (section) => pathname === section.path || pathname.startsWith(`${section.path}/`),
  ) ?? BCC_SECTIONS[0];
}

export function buildBccBreadcrumbs(pathname) {
  const section = findBccSectionByPath(pathname);
  const leaf = findBccLeafByPath(pathname);
  const items = [
    { label: 'Blog Command Centre', to: ROUTES.BLOG_COMMAND_CENTRE },
  ];
  if (section && section.id !== 'overview') {
    items.push({ label: section.label, to: section.path });
  }
  if (leaf && leaf.path !== ROUTES.BLOG_COMMAND_CENTRE && leaf.path !== section?.path) {
    items.push({ label: leaf.label });
  } else if (pathname === ROUTES.BLOG_COMMAND_CENTRE) {
    items.push({ label: 'Overview' });
  }
  return items;
}
