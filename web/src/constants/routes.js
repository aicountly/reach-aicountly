export const ROUTES = {
  LOGIN: '/login',

  DASHBOARD: '/',

  BLOG_LIST:    '/blog',
  BLOG_NEW:     '/blog/new',
  BLOG_EDIT:    '/blog/:id/edit',
  BLOG_DETAIL:  '/blog/:id',

  CONTENT_CALENDAR: '/calendar',

  CAMPAIGN_LIST:   '/campaigns',
  CAMPAIGN_NEW:    '/campaigns/new',
  CAMPAIGN_EDIT:   '/campaigns/:id/edit',
  CAMPAIGN_DETAIL: '/campaigns/:id',

  LANDING_LIST:   '/landing',
  LANDING_DETAIL: '/landing/:id',

  SOCIAL_PLANNER: '/social',
  SOCIAL_QUEUE:   '/social/queue',

  EMAIL_LIST:    '/email',
  EMAIL_DETAIL:  '/email/:id',

  WHATSAPP_LIST:   '/whatsapp',
  WHATSAPP_DETAIL: '/whatsapp/:id',

  SEO_PLANS:    '/seo/plans',
  KEYWORD_IDEAS:'/seo/keywords',

  CREATIVE_BRIEFS: '/creative-briefs',

  ANALYTICS: '/analytics',

  LEADS:        '/leads',
  ENGAGE_PUSH:  '/leads/engage-push',

  BOT_QUEUE:    '/bot/queue',
  BOT_REPORTS:  '/bot/reports',
  BOT_REPORT_DETAIL: '/bot/reports/:id',

  APPROVALS: '/approvals',

  SETTINGS:    '/admin/settings',
  BOT_SETTINGS:'/admin/bot-mode',
  AUDIT_LOGS:  '/admin/audit-logs',
  API_HEALTH:  '/admin/api-health',
  CONSOLE_SYNC:'/admin/console-sync',
  WORKER_STATUS:'/admin/worker-status',
  JOBS: '/admin/jobs',
  JOB_DETAIL: '/admin/jobs/:id',
  LOCAL_BOT_REPORTS: '/admin/local-bot-reports',
  FORBIDDEN: '/forbidden',

  // Phase 1: Knowledge Foundation
  KNOWLEDGE:            '/knowledge',
  KNOWLEDGE_PRODUCTS:   '/knowledge/products',
  KNOWLEDGE_PRODUCT:    '/knowledge/products/:id',
  KNOWLEDGE_PERSONAS:   '/knowledge/personas',
  KNOWLEDGE_INDUSTRIES: '/knowledge/industries',
  KNOWLEDGE_MARKETS:    '/knowledge/markets',
  KNOWLEDGE_PROBLEMS:   '/knowledge/problems',
  KNOWLEDGE_INTENTS:    '/knowledge/search-intents',
  KNOWLEDGE_CLUSTERS:   '/knowledge/topic-clusters',
  KNOWLEDGE_SOURCES:    '/knowledge/sources',
  KNOWLEDGE_CITATIONS:  '/knowledge/citations',
  KNOWLEDGE_CLAIMS:     '/knowledge/claims',
  KNOWLEDGE_BRAND_RULES:   '/knowledge/brand-rules',
  KNOWLEDGE_POLICIES:      '/knowledge/content-policies',
  KNOWLEDGE_COMPLETENESS:  '/knowledge/completeness',
};
