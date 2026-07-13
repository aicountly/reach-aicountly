export const ROUTES = {
  LOGIN: '/login',

  DASHBOARD: '/',

  BLOG_LIST:    '/blog',
  BLOG_NEW:     '/blog/new',
  BLOG_EDIT:    '/blog/:id/edit',
  BLOG_DETAIL:  '/blog/:id',

  CONTENT_CALENDAR_LEGACY: '/calendar',

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

  // Phase 2: Unified Content Studio
  CONTENT:             '/content',
  CONTENT_NEW:         '/content/new',
  CONTENT_DETAIL:      '/content/:id',
  CONTENT_EDIT:        '/content/:id/edit',
  CONTENT_VERSIONS:    '/content/:id/versions',
  CONTENT_BRIEF:       '/content/:id/brief',
  CONTENT_COMMENTS:    '/content/:id/comments',
  CONTENT_VALIDATIONS: '/content/:id/validations',
  CONTENT_SCHEDULE:    '/content/:id/schedule',
  CONTENT_CALENDAR:    '/content/calendar',
  CONTENT_DAILY_PACK:  '/content/daily-pack',
  CONTENT_TEMPLATES:   '/content/templates',

  // Phase 3: AI Control Centre
  AI:                    '/ai',
  AI_DASHBOARD:          '/ai/dashboard',
  AI_PROVIDERS:          '/ai/providers',
  AI_PROVIDER_DETAIL:    '/ai/providers/:id',
  AI_MODELS:             '/ai/models',
  AI_ROUTING:            '/ai/routing',
  AI_PROMPTS:            '/ai/prompts',
  AI_PROMPT_DETAIL:      '/ai/prompts/:id',
  AI_GENERATIONS:        '/ai/generations',
  AI_GENERATION_DETAIL:  '/ai/generations/:uuid',
  AI_USAGE:              '/ai/usage',
  AI_BUDGETS:            '/ai/budgets',
  AI_VALIDATIONS:        '/ai/validations',
  AI_HEALTH:             '/ai/health',
};
