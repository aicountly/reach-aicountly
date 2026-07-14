<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', static function () {
    return service('response')->setJSON([
        'ok'      => true,
        'service' => 'aicountly-reach-api',
        'version' => 'v1',
        'docs'    => '/api/v1',
    ]);
});

$routes->get('health', static function () {
    $jwtSecret = (string) env('JWT_SECRET', '');
    $jwtOk     = $jwtSecret !== '' && strlen($jwtSecret) >= 32;
    $db        = false;
    try {
        \Config\Database::connect()->query('SELECT 1');
        $db = true;
    } catch (\Throwable) {
        $db = false;
    }
    return service('response')->setJSON([
        'ok'        => $jwtOk && $db,
        'service'   => 'aicountly-reach-api',
        'status'    => $jwtOk && $db ? 'ready' : 'misconfigured',
        'timestamp' => gmdate('c'),
        'checks'    => [
            'jwt_secret' => $jwtOk ? 'ok' : 'missing or too short (need 32+ chars in api/.env)',
            'database'   => $db    ? 'ok' : 'unreachable',
        ],
    ]);
});

/*
 * -----------------------------------------------------------------------------
 * Reach API v1 routes.
 *
 * Filter model (Phase 0):
 *
 *   - `jwt`             validates Bearer token, populates $request->reachUser.
 *   - `permission:xxx`  enforces the RBAC permission via PermissionFilter.
 *   - `public-capture`  signed public form-submit endpoint (no user auth).
 *   - `console-token`   inbound-from-Console (X-Console-Token) endpoints.
 *
 * Every authenticated route declares its own `permission:<slug>` filter.
 * The old blanket `super-admin` group filter is no longer applied here;
 * the alias is kept for backward compatibility only.
 * -----------------------------------------------------------------------------
 */
$routes->group('v1', static function ($routes) {

    // ---------------------------------------------------------------------
    // Public auth (no JWT). Console SSO is the sole sign-in path.
    // Rate-limited per-IP to make credential-stuffing / SSO abuse noisy.
    // ---------------------------------------------------------------------
    $routes->post('auth/login',           'Api\\V1\\AuthController::login',          ['filter' => 'throttle:auth']);
    $routes->post('auth/refresh',         'Api\\V1\\AuthController::refresh',        ['filter' => 'throttle:auth']);
    $routes->get('auth/sso-callback',     'Api\\V1\\AuthController::ssoCallback',    ['filter' => 'throttle:auth']);
    $routes->post('auth/controller-sso',  'Api\\V1\\AuthController::controllerSso',  ['filter' => 'throttle:auth']);
    $routes->post('auth/console-session', 'Api\\V1\\AuthController::consoleSession', ['filter' => 'throttle:auth']);

    // ---------------------------------------------------------------------
    // Public lead capture (form/landing/embed). Signed with PUBLIC_LEAD_CAPTURE_TOKEN.
    // Two-layer throttle: per-IP and per-token.
    // ---------------------------------------------------------------------
    $routes->group('public', ['filter' => 'public-capture'], static function ($routes) {
        $routes->post('leads/capture', 'Api\\V1\\LeadController::publicCapture', ['filter' => ['throttle:public_capture', 'throttle:public_capture_token']]);
    });

    // ---------------------------------------------------------------------
    // Inbound from Console (X-Console-Token).
    // ---------------------------------------------------------------------
    $routes->group('portal', ['filter' => 'console-token'], static function ($routes) {
        $routes->get('bot/health',             'Api\\V1\\Portal\\BotController::health');
        $routes->get('bot/mode',               'Api\\V1\\Portal\\BotController::getMode');
        $routes->put('bot/mode',               'Api\\V1\\Portal\\BotController::setMode');
        $routes->post('bot/approval-callback', 'Api\\V1\\Portal\\BotController::approvalCallback');
    });

    // ---------------------------------------------------------------------
    // Authenticated V1 API — every route is permission-gated individually.
    // ---------------------------------------------------------------------
    $routes->group('', ['filter' => 'jwt'], static function ($routes) {
        // Identity/session endpoints — permission-free, any authenticated user.
        $routes->get('me',           'Api\\V1\\AuthController::me');
        $routes->post('auth/logout', 'Api\\V1\\AuthController::logout');
        $routes->get('auth/controller-apps/launcher', 'Api\\V1\\AuthController::controllerAppsLauncher');
        $routes->get('auth/sso/launch-url', 'Api\\V1\\AuthController::ssoLaunchUrl');

        // Dashboard
        $routes->get('dashboard/summary', 'Api\\V1\\DashboardController::summary', ['filter' => 'permission:dashboard.view']);
        $routes->get('dashboard/counts',  'Api\\V1\\DashboardController::counts',  ['filter' => 'permission:dashboard.view']);

        // Blog
        $routes->get('blog/posts',                        'Api\\V1\\BlogController::index',          ['filter' => 'permission:blog.view']);
        $routes->post('blog/posts',                       'Api\\V1\\BlogController::store',          ['filter' => 'permission:blog.create']);
        $routes->get('blog/posts/(:num)',                 'Api\\V1\\BlogController::show/$1',        ['filter' => 'permission:blog.view']);
        $routes->put('blog/posts/(:num)',                 'Api\\V1\\BlogController::update/$1',      ['filter' => 'permission:blog.edit']);
        $routes->delete('blog/posts/(:num)',              'Api\\V1\\BlogController::destroy/$1',     ['filter' => 'permission:blog.edit']);
        $routes->post('blog/posts/(:num)/transition',     'Api\\V1\\BlogController::transition/$1',  ['filter' => 'permission:blog.submit']);
        $routes->post('blog/posts/(:num)/approve',        'Api\\V1\\BlogController::approve/$1',     ['filter' => ['permission:blog.approve', 'throttle:approval']]);
        $routes->post('blog/posts/(:num)/reject',         'Api\\V1\\BlogController::reject/$1',      ['filter' => ['permission:blog.approve', 'throttle:approval']]);
        $routes->post('blog/posts/(:num)/publish',        'Api\\V1\\BlogController::publish/$1',     ['filter' => 'permission:blog.publish']);
        $routes->get('blog/posts/(:num)/versions',        'Api\\V1\\BlogVersionController::index/$1',      ['filter' => 'permission:blog.view']);
        $routes->get('blog/posts/(:num)/versions/(:num)', 'Api\\V1\\BlogVersionController::show/$1/$2',    ['filter' => 'permission:blog.view']);

        // Content calendar
        $routes->get('calendar/items',           'Api\\V1\\ContentCalendarController::index',      ['filter' => 'permission:blog.view']);
        $routes->post('calendar/items',          'Api\\V1\\ContentCalendarController::store',      ['filter' => 'permission:blog.edit']);
        $routes->put('calendar/items/(:num)',    'Api\\V1\\ContentCalendarController::update/$1',  ['filter' => 'permission:blog.edit']);
        $routes->delete('calendar/items/(:num)', 'Api\\V1\\ContentCalendarController::destroy/$1', ['filter' => 'permission:blog.edit']);

        // Campaigns
        $routes->get('campaigns',                  'Api\\V1\\CampaignController::index',         ['filter' => 'permission:campaign.view']);
        $routes->post('campaigns',                 'Api\\V1\\CampaignController::store',         ['filter' => 'permission:campaign.create']);
        $routes->get('campaigns/(:num)',           'Api\\V1\\CampaignController::show/$1',       ['filter' => 'permission:campaign.view']);
        $routes->put('campaigns/(:num)',           'Api\\V1\\CampaignController::update/$1',     ['filter' => 'permission:campaign.edit']);
        $routes->delete('campaigns/(:num)',        'Api\\V1\\CampaignController::destroy/$1',    ['filter' => 'permission:campaign.edit']);
        $routes->post('campaigns/(:num)/approve',  'Api\\V1\\CampaignController::approve/$1',    ['filter' => 'permission:campaign.approve']);
        $routes->post('campaigns/(:num)/status',   'Api\\V1\\CampaignController::setStatus/$1',  ['filter' => 'permission:campaign.dispatch']);

        // Landing pages
        $routes->get('landing-pages',           'Api\\V1\\LandingPageController::index',      ['filter' => 'permission:campaign.view']);
        $routes->post('landing-pages',          'Api\\V1\\LandingPageController::store',      ['filter' => 'permission:campaign.create']);
        $routes->get('landing-pages/(:num)',    'Api\\V1\\LandingPageController::show/$1',    ['filter' => 'permission:campaign.view']);
        $routes->put('landing-pages/(:num)',    'Api\\V1\\LandingPageController::update/$1',  ['filter' => 'permission:campaign.edit']);
        $routes->delete('landing-pages/(:num)', 'Api\\V1\\LandingPageController::destroy/$1', ['filter' => 'permission:campaign.edit']);

        // Social planner + queue
        $routes->get('social/posts',                     'Api\\V1\\SocialPostController::index',        ['filter' => 'permission:social.view']);
        $routes->post('social/posts',                    'Api\\V1\\SocialPostController::store',        ['filter' => 'permission:social.create']);
        $routes->get('social/posts/(:num)',              'Api\\V1\\SocialPostController::show/$1',      ['filter' => 'permission:social.view']);
        $routes->put('social/posts/(:num)',              'Api\\V1\\SocialPostController::update/$1',    ['filter' => 'permission:social.edit']);
        $routes->delete('social/posts/(:num)',           'Api\\V1\\SocialPostController::destroy/$1',   ['filter' => 'permission:social.edit']);
        $routes->post('social/posts/(:num)/approve',     'Api\\V1\\SocialPostController::approve/$1',   ['filter' => 'permission:social.approve']);
        $routes->post('social/posts/(:num)/reject',      'Api\\V1\\SocialPostController::reject/$1',    ['filter' => 'permission:social.approve']);
        $routes->post('social/posts/(:num)/mark-posted', 'Api\\V1\\SocialPostController::markPosted/$1',['filter' => 'permission:social.dispatch']);
        $routes->get('social/queue',                     'Api\\V1\\SocialQueueController::index',       ['filter' => 'permission:social.view']);

        // Email + WhatsApp campaigns
        $routes->get('email/campaigns',                    'Api\\V1\\EmailCampaignController::index',        ['filter' => 'permission:email.view']);
        $routes->post('email/campaigns',                   'Api\\V1\\EmailCampaignController::store',        ['filter' => 'permission:email.create']);
        $routes->get('email/campaigns/(:num)',             'Api\\V1\\EmailCampaignController::show/$1',      ['filter' => 'permission:email.view']);
        $routes->put('email/campaigns/(:num)',             'Api\\V1\\EmailCampaignController::update/$1',    ['filter' => 'permission:email.edit']);
        $routes->post('email/campaigns/(:num)/mark-sent',  'Api\\V1\\EmailCampaignController::markSent/$1',  ['filter' => 'permission:email.dispatch']);

        $routes->get('whatsapp/campaigns',                    'Api\\V1\\WhatsAppCampaignController::index',       ['filter' => 'permission:whatsapp.view']);
        $routes->post('whatsapp/campaigns',                   'Api\\V1\\WhatsAppCampaignController::store',       ['filter' => 'permission:whatsapp.create']);
        $routes->get('whatsapp/campaigns/(:num)',             'Api\\V1\\WhatsAppCampaignController::show/$1',     ['filter' => 'permission:whatsapp.view']);
        $routes->put('whatsapp/campaigns/(:num)',             'Api\\V1\\WhatsAppCampaignController::update/$1',   ['filter' => 'permission:whatsapp.edit']);
        $routes->post('whatsapp/campaigns/(:num)/mark-sent',  'Api\\V1\\WhatsAppCampaignController::markSent/$1', ['filter' => 'permission:whatsapp.dispatch']);

        // SEO planner + keyword ideas
        $routes->get('seo/plans',              'Api\\V1\\SeoPlanController::index',        ['filter' => 'permission:blog.view']);
        $routes->post('seo/plans',             'Api\\V1\\SeoPlanController::store',        ['filter' => 'permission:blog.edit']);
        $routes->get('seo/plans/(:num)',       'Api\\V1\\SeoPlanController::show/$1',      ['filter' => 'permission:blog.view']);
        $routes->put('seo/plans/(:num)',       'Api\\V1\\SeoPlanController::update/$1',    ['filter' => 'permission:blog.edit']);
        $routes->delete('seo/plans/(:num)',    'Api\\V1\\SeoPlanController::destroy/$1',   ['filter' => 'permission:blog.edit']);

        $routes->get('seo/keywords',           'Api\\V1\\KeywordIdeaController::index',      ['filter' => 'permission:blog.view']);
        $routes->post('seo/keywords',          'Api\\V1\\KeywordIdeaController::store',      ['filter' => 'permission:blog.edit']);
        $routes->put('seo/keywords/(:num)',    'Api\\V1\\KeywordIdeaController::update/$1',  ['filter' => 'permission:blog.edit']);
        $routes->delete('seo/keywords/(:num)', 'Api\\V1\\KeywordIdeaController::destroy/$1', ['filter' => 'permission:blog.edit']);

        // Creative briefs
        $routes->get('creative-briefs',           'Api\\V1\\CreativeBriefController::index',        ['filter' => 'permission:campaign.view']);
        $routes->post('creative-briefs',          'Api\\V1\\CreativeBriefController::store',        ['filter' => 'permission:campaign.create']);
        $routes->get('creative-briefs/(:num)',    'Api\\V1\\CreativeBriefController::show/$1',      ['filter' => 'permission:campaign.view']);
        $routes->put('creative-briefs/(:num)',    'Api\\V1\\CreativeBriefController::update/$1',    ['filter' => 'permission:campaign.edit']);
        $routes->delete('creative-briefs/(:num)', 'Api\\V1\\CreativeBriefController::destroy/$1',   ['filter' => 'permission:campaign.edit']);

        // Analytics
        $routes->get('analytics/summary',               'Api\\V1\\AnalyticsController::summary',            ['filter' => 'permission:analytics.view']);
        $routes->get('analytics/traffic',               'Api\\V1\\AnalyticsController::traffic',            ['filter' => 'permission:analytics.view']);
        $routes->get('analytics/traffic/overview',      'Api\\V1\\AnalyticsController::trafficOverview',    ['filter' => 'permission:analytics.view']);
        $routes->get('analytics/traffic/sources',       'Api\\V1\\AnalyticsController::trafficSources',     ['filter' => 'permission:analytics.view']);
        $routes->get('analytics/traffic/leads',         'Api\\V1\\AnalyticsController::trafficLeads',       ['filter' => 'permission:analytics.view']);
        $routes->get('analytics/traffic/config-status', 'Api\\V1\\AnalyticsController::trafficConfigStatus',['filter' => 'permission:analytics.view']);
        $routes->get('analytics/providers',             'Api\\V1\\AnalyticsController::providers',          ['filter' => 'permission:analytics.view']);

        // Leads
        $routes->get('leads',           'Api\\V1\\LeadController::index',      ['filter' => 'permission:lead.view']);
        $routes->post('leads',          'Api\\V1\\LeadController::store',      ['filter' => 'permission:lead.manage']);
        $routes->get('leads/(:num)',    'Api\\V1\\LeadController::show/$1',    ['filter' => 'permission:lead.view']);
        $routes->put('leads/(:num)',    'Api\\V1\\LeadController::update/$1',  ['filter' => 'permission:lead.manage']);
        $routes->delete('leads/(:num)', 'Api\\V1\\LeadController::destroy/$1', ['filter' => 'permission:lead.manage']);

        $routes->get('engage-push',               'Api\\V1\\EngagePushController::index',    ['filter' => 'permission:lead.view']);
        $routes->post('engage-push/(:num)',       'Api\\V1\\EngagePushController::push/$1',  ['filter' => ['permission:lead.manage', 'throttle:integration']]);
        $routes->post('engage-push/(:num)/retry', 'Api\\V1\\EngagePushController::retry/$1', ['filter' => ['permission:lead.manage', 'throttle:integration']]);

        // Marketing Bot
        $routes->get('bot/settings',              'Api\\V1\\BotSettingsController::index',            ['filter' => 'permission:bot.view']);
        $routes->put('bot/settings',              'Api\\V1\\BotSettingsController::update',           ['filter' => 'permission:bot.configure']);
        $routes->post('bot/dispatch',             'Api\\V1\\MarketingBotController::dispatch',        ['filter' => ['permission:bot.dispatch', 'throttle:bot_dispatch']]);
        $routes->get('bot/queue',                 'Api\\V1\\MarketingBotController::queue',           ['filter' => 'permission:bot.view']);
        $routes->get('bot/queue/(:num)',          'Api\\V1\\MarketingBotController::queueItem/$1',    ['filter' => 'permission:bot.view']);
        $routes->post('bot/queue/(:num)/approve', 'Api\\V1\\MarketingBotController::approveItem/$1',  ['filter' => ['permission:approval.decide', 'throttle:approval']]);
        $routes->post('bot/queue/(:num)/reject',  'Api\\V1\\MarketingBotController::rejectItem/$1',   ['filter' => ['permission:approval.decide', 'throttle:approval']]);
        $routes->get('bot/reports',               'Api\\V1\\BotReportController::index',              ['filter' => 'permission:bot.view']);
        $routes->get('bot/reports/(:num)',        'Api\\V1\\BotReportController::show/$1',            ['filter' => 'permission:bot.view']);

        // Approvals
        $routes->get('approvals',              'Api\\V1\\ApprovalController::index',       ['filter' => 'permission:approval.view']);
        $routes->get('approvals/(:num)',       'Api\\V1\\ApprovalController::show/$1',     ['filter' => 'permission:approval.view']);
        $routes->post('approvals/(:num)/decide', 'Api\\V1\\ApprovalController::decide/$1', ['filter' => ['permission:approval.decide', 'throttle:approval']]);

        // Admin
        $routes->get('settings',                  'Api\\V1\\SettingsController::index',                ['filter' => 'permission:settings.view']);
        $routes->put('settings',                  'Api\\V1\\SettingsController::update',               ['filter' => 'permission:settings.manage']);

        $routes->get('audit-logs',                'Api\\V1\\AuditLogController::index',                ['filter' => 'permission:audit.view']);

        $routes->get('admin/api-health',          'Api\\V1\\HealthController::detailed',               ['filter' => 'permission:settings.view']);
        $routes->get('admin/console-sync-status', 'Api\\V1\\ConsoleSyncStatusController::index',       ['filter' => 'permission:integration.view']);
        $routes->get('admin/worker-status',       'Api\\V1\\WorkerStatusController::index',            ['filter' => 'permission:job.view']);
        $routes->post('admin/worker-status/ping', 'Api\\V1\\WorkerStatusController::ping',             ['filter' => ['permission:job.view', 'throttle:integration']]);

        // Job Monitor (Phase 0)
        $routes->get('jobs',                   'Api\\V1\\JobController::index',   ['filter' => 'permission:job.view']);
        $routes->get('jobs/(:num)',            'Api\\V1\\JobController::show/$1', ['filter' => 'permission:job.view']);
        $routes->post('jobs/(:num)/retry',     'Api\\V1\\JobController::retry/$1',  ['filter' => ['permission:job.retry',  'throttle:integration']]);
        $routes->post('jobs/(:num)/cancel',    'Api\\V1\\JobController::cancel/$1', ['filter' => ['permission:job.cancel', 'throttle:integration']]);

        // ── Phase 1: Knowledge Foundation ─────────────────────────────────────
        $routes->group('knowledge', static function ($routes) {

            // Grounding (read-only, approved data only, no AI calls)
            $routes->get('grounding/product/(:segment)',  'Api\\V1\\Knowledge\\GroundingController::product/$1', ['filter' => 'permission:knowledge.view']);
            $routes->get('grounding/intent/(:num)',       'Api\\V1\\Knowledge\\GroundingController::intent/$1',  ['filter' => 'permission:knowledge.view']);
            $routes->post('grounding/context',            'Api\\V1\\Knowledge\\GroundingController::context',    ['filter' => 'permission:knowledge.view']);

            // Completeness scoring
            $routes->get('completeness',               'Api\\V1\\Knowledge\\CompletenessController::index',       ['filter' => 'permission:knowledge.view']);
            $routes->get('completeness/product/(:num)','Api\\V1\\Knowledge\\CompletenessController::product/$1',  ['filter' => 'permission:knowledge.view']);

            // Products
            $routes->get('products',                          'Api\\V1\\Knowledge\\ProductController::index',          ['filter' => 'permission:product.view']);
            $routes->post('products',                         'Api\\V1\\Knowledge\\ProductController::store',          ['filter' => 'permission:product.manage']);
            $routes->get('products/(:num)',                   'Api\\V1\\Knowledge\\ProductController::show/$1',        ['filter' => 'permission:product.view']);
            $routes->put('products/(:num)',                   'Api\\V1\\Knowledge\\ProductController::update/$1',      ['filter' => 'permission:product.manage']);
            $routes->delete('products/(:num)',                'Api\\V1\\Knowledge\\ProductController::destroy/$1',     ['filter' => 'permission:product.manage']);
            $routes->post('products/(:num)/submit',           'Api\\V1\\Knowledge\\ProductController::submit/$1',     ['filter' => 'permission:knowledge.submit']);
            $routes->post('products/(:num)/approve',          'Api\\V1\\Knowledge\\ProductController::approve/$1',    ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('products/(:num)/reject',           'Api\\V1\\Knowledge\\ProductController::reject/$1',     ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('products/(:num)/archive',          'Api\\V1\\Knowledge\\ProductController::archive/$1',    ['filter' => 'permission:knowledge.archive']);
            $routes->get('products/(:num)/aliases',           'Api\\V1\\Knowledge\\ProductController::aliases/$1',    ['filter' => 'permission:product.view']);
            $routes->post('products/(:num)/aliases',          'Api\\V1\\Knowledge\\ProductController::storeAlias/$1', ['filter' => 'permission:product.manage']);

            // Modules
            $routes->get('modules',                   'Api\\V1\\Knowledge\\ModuleController::index',       ['filter' => 'permission:product.view']);
            $routes->post('modules',                  'Api\\V1\\Knowledge\\ModuleController::store',       ['filter' => 'permission:product.manage']);
            $routes->get('modules/(:num)',            'Api\\V1\\Knowledge\\ModuleController::show/$1',     ['filter' => 'permission:product.view']);
            $routes->put('modules/(:num)',            'Api\\V1\\Knowledge\\ModuleController::update/$1',   ['filter' => 'permission:product.manage']);
            $routes->delete('modules/(:num)',         'Api\\V1\\Knowledge\\ModuleController::destroy/$1',  ['filter' => 'permission:product.manage']);
            $routes->post('modules/(:num)/submit',    'Api\\V1\\Knowledge\\ModuleController::submit/$1',   ['filter' => 'permission:knowledge.submit']);
            $routes->post('modules/(:num)/approve',   'Api\\V1\\Knowledge\\ModuleController::approve/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('modules/(:num)/reject',    'Api\\V1\\Knowledge\\ModuleController::reject/$1',   ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Features
            $routes->get('features',                  'Api\\V1\\Knowledge\\FeatureController::index',      ['filter' => 'permission:product.view']);
            $routes->post('features',                 'Api\\V1\\Knowledge\\FeatureController::store',      ['filter' => 'permission:product.manage']);
            $routes->get('features/(:num)',           'Api\\V1\\Knowledge\\FeatureController::show/$1',    ['filter' => 'permission:product.view']);
            $routes->put('features/(:num)',           'Api\\V1\\Knowledge\\FeatureController::update/$1',  ['filter' => 'permission:product.manage']);
            $routes->delete('features/(:num)',        'Api\\V1\\Knowledge\\FeatureController::destroy/$1', ['filter' => 'permission:product.manage']);
            $routes->post('features/(:num)/submit',   'Api\\V1\\Knowledge\\FeatureController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('features/(:num)/approve',  'Api\\V1\\Knowledge\\FeatureController::approve/$1', ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('features/(:num)/reject',   'Api\\V1\\Knowledge\\FeatureController::reject/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Personas
            $routes->get('personas',                  'Api\\V1\\Knowledge\\PersonaController::index',      ['filter' => 'permission:persona.view']);
            $routes->post('personas',                 'Api\\V1\\Knowledge\\PersonaController::store',      ['filter' => 'permission:persona.manage']);
            $routes->get('personas/(:num)',           'Api\\V1\\Knowledge\\PersonaController::show/$1',    ['filter' => 'permission:persona.view']);
            $routes->put('personas/(:num)',           'Api\\V1\\Knowledge\\PersonaController::update/$1',  ['filter' => 'permission:persona.manage']);
            $routes->delete('personas/(:num)',        'Api\\V1\\Knowledge\\PersonaController::destroy/$1', ['filter' => 'permission:persona.manage']);
            $routes->post('personas/(:num)/submit',   'Api\\V1\\Knowledge\\PersonaController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('personas/(:num)/approve',  'Api\\V1\\Knowledge\\PersonaController::approve/$1', ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('personas/(:num)/reject',   'Api\\V1\\Knowledge\\PersonaController::reject/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Industries
            $routes->get('industries',                  'Api\\V1\\Knowledge\\IndustryController::index',      ['filter' => 'permission:industry.view']);
            $routes->post('industries',                 'Api\\V1\\Knowledge\\IndustryController::store',      ['filter' => 'permission:industry.manage']);
            $routes->get('industries/(:num)',           'Api\\V1\\Knowledge\\IndustryController::show/$1',    ['filter' => 'permission:industry.view']);
            $routes->put('industries/(:num)',           'Api\\V1\\Knowledge\\IndustryController::update/$1',  ['filter' => 'permission:industry.manage']);
            $routes->delete('industries/(:num)',        'Api\\V1\\Knowledge\\IndustryController::destroy/$1', ['filter' => 'permission:industry.manage']);
            $routes->post('industries/(:num)/submit',   'Api\\V1\\Knowledge\\IndustryController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('industries/(:num)/approve',  'Api\\V1\\Knowledge\\IndustryController::approve/$1', ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('industries/(:num)/reject',   'Api\\V1\\Knowledge\\IndustryController::reject/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Markets
            $routes->get('markets',                  'Api\\V1\\Knowledge\\MarketController::index',      ['filter' => 'permission:knowledge.view']);
            $routes->post('markets',                 'Api\\V1\\Knowledge\\MarketController::store',      ['filter' => 'permission:knowledge.create']);
            $routes->get('markets/(:num)',           'Api\\V1\\Knowledge\\MarketController::show/$1',    ['filter' => 'permission:knowledge.view']);
            $routes->put('markets/(:num)',           'Api\\V1\\Knowledge\\MarketController::update/$1',  ['filter' => 'permission:knowledge.edit']);
            $routes->delete('markets/(:num)',        'Api\\V1\\Knowledge\\MarketController::destroy/$1', ['filter' => 'permission:knowledge.edit']);
            $routes->post('markets/(:num)/submit',   'Api\\V1\\Knowledge\\MarketController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('markets/(:num)/approve',  'Api\\V1\\Knowledge\\MarketController::approve/$1', ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('markets/(:num)/reject',   'Api\\V1\\Knowledge\\MarketController::reject/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Business Problems
            $routes->get('problems',                  'Api\\V1\\Knowledge\\BusinessProblemController::index',      ['filter' => 'permission:knowledge.view']);
            $routes->post('problems',                 'Api\\V1\\Knowledge\\BusinessProblemController::store',      ['filter' => 'permission:knowledge.create']);
            $routes->get('problems/(:num)',           'Api\\V1\\Knowledge\\BusinessProblemController::show/$1',    ['filter' => 'permission:knowledge.view']);
            $routes->put('problems/(:num)',           'Api\\V1\\Knowledge\\BusinessProblemController::update/$1',  ['filter' => 'permission:knowledge.edit']);
            $routes->delete('problems/(:num)',        'Api\\V1\\Knowledge\\BusinessProblemController::destroy/$1', ['filter' => 'permission:knowledge.edit']);
            $routes->post('problems/(:num)/submit',   'Api\\V1\\Knowledge\\BusinessProblemController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('problems/(:num)/approve',  'Api\\V1\\Knowledge\\BusinessProblemController::approve/$1', ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('problems/(:num)/reject',   'Api\\V1\\Knowledge\\BusinessProblemController::reject/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Search Intents
            $routes->get('search-intents',                               'Api\\V1\\Knowledge\\SearchIntentController::index',          ['filter' => 'permission:intent.view']);
            $routes->post('search-intents',                              'Api\\V1\\Knowledge\\SearchIntentController::store',          ['filter' => 'permission:intent.manage']);
            $routes->get('search-intents/(:num)',                        'Api\\V1\\Knowledge\\SearchIntentController::show/$1',        ['filter' => 'permission:intent.view']);
            $routes->put('search-intents/(:num)',                        'Api\\V1\\Knowledge\\SearchIntentController::update/$1',      ['filter' => 'permission:intent.manage']);
            $routes->delete('search-intents/(:num)',                     'Api\\V1\\Knowledge\\SearchIntentController::destroy/$1',     ['filter' => 'permission:intent.manage']);
            $routes->post('search-intents/(:num)/submit',                'Api\\V1\\Knowledge\\SearchIntentController::submit/$1',      ['filter' => 'permission:knowledge.submit']);
            $routes->post('search-intents/(:num)/approve',               'Api\\V1\\Knowledge\\SearchIntentController::approve/$1',     ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('search-intents/(:num)/reject',                'Api\\V1\\Knowledge\\SearchIntentController::reject/$1',      ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('search-intents/(:num)/sync-relations',        'Api\\V1\\Knowledge\\SearchIntentController::syncRelations/$1', ['filter' => 'permission:intent.manage']);

            // Topic Clusters
            $routes->get('topic-clusters',                 'Api\\V1\\Knowledge\\TopicClusterController::index',      ['filter' => 'permission:knowledge.view']);
            $routes->post('topic-clusters',                'Api\\V1\\Knowledge\\TopicClusterController::store',      ['filter' => 'permission:knowledge.create']);
            $routes->get('topic-clusters/(:num)',          'Api\\V1\\Knowledge\\TopicClusterController::show/$1',    ['filter' => 'permission:knowledge.view']);
            $routes->put('topic-clusters/(:num)',          'Api\\V1\\Knowledge\\TopicClusterController::update/$1',  ['filter' => 'permission:knowledge.edit']);
            $routes->delete('topic-clusters/(:num)',       'Api\\V1\\Knowledge\\TopicClusterController::destroy/$1', ['filter' => 'permission:knowledge.edit']);
            $routes->post('topic-clusters/(:num)/submit',  'Api\\V1\\Knowledge\\TopicClusterController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('topic-clusters/(:num)/approve', 'Api\\V1\\Knowledge\\TopicClusterController::approve/$1', ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('topic-clusters/(:num)/reject',  'Api\\V1\\Knowledge\\TopicClusterController::reject/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Claims
            $routes->get('claims',                       'Api\\V1\\Knowledge\\ClaimController::index',         ['filter' => 'permission:claim.view']);
            $routes->post('claims',                      'Api\\V1\\Knowledge\\ClaimController::store',         ['filter' => 'permission:claim.manage']);
            $routes->get('claims/(:num)',                'Api\\V1\\Knowledge\\ClaimController::show/$1',       ['filter' => 'permission:claim.view']);
            $routes->put('claims/(:num)',                'Api\\V1\\Knowledge\\ClaimController::update/$1',     ['filter' => 'permission:claim.manage']);
            $routes->delete('claims/(:num)',             'Api\\V1\\Knowledge\\ClaimController::destroy/$1',    ['filter' => 'permission:claim.manage']);
            $routes->post('claims/(:num)/submit',        'Api\\V1\\Knowledge\\ClaimController::submit/$1',     ['filter' => 'permission:knowledge.submit']);
            $routes->post('claims/(:num)/approve',       'Api\\V1\\Knowledge\\ClaimController::approve/$1',    ['filter' => ['permission:claim.approve', 'throttle:approval']]);
            $routes->post('claims/(:num)/reject',        'Api\\V1\\Knowledge\\ClaimController::reject/$1',     ['filter' => ['permission:claim.approve', 'throttle:approval']]);
            $routes->post('claims/(:num)/sync-evidence', 'Api\\V1\\Knowledge\\ClaimController::syncEvidence/$1', ['filter' => 'permission:claim.manage']);

            // Evidence
            $routes->get('evidence',                  'Api\\V1\\Knowledge\\EvidenceController::index',      ['filter' => 'permission:knowledge.view']);
            $routes->post('evidence',                 'Api\\V1\\Knowledge\\EvidenceController::store',      ['filter' => 'permission:knowledge.create']);
            $routes->get('evidence/(:num)',           'Api\\V1\\Knowledge\\EvidenceController::show/$1',    ['filter' => 'permission:knowledge.view']);
            $routes->put('evidence/(:num)',           'Api\\V1\\Knowledge\\EvidenceController::update/$1',  ['filter' => 'permission:knowledge.edit']);
            $routes->delete('evidence/(:num)',        'Api\\V1\\Knowledge\\EvidenceController::destroy/$1', ['filter' => 'permission:knowledge.edit']);
            $routes->post('evidence/(:num)/submit',   'Api\\V1\\Knowledge\\EvidenceController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('evidence/(:num)/approve',  'Api\\V1\\Knowledge\\EvidenceController::approve/$1', ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);
            $routes->post('evidence/(:num)/reject',   'Api\\V1\\Knowledge\\EvidenceController::reject/$1',  ['filter' => ['permission:knowledge.approve', 'throttle:approval']]);

            // Sources
            $routes->get('sources',                  'Api\\V1\\Knowledge\\SourceController::index',      ['filter' => 'permission:source.view']);
            $routes->post('sources',                 'Api\\V1\\Knowledge\\SourceController::store',      ['filter' => 'permission:source.manage']);
            $routes->get('sources/(:num)',           'Api\\V1\\Knowledge\\SourceController::show/$1',    ['filter' => 'permission:source.view']);
            $routes->put('sources/(:num)',           'Api\\V1\\Knowledge\\SourceController::update/$1',  ['filter' => 'permission:source.manage']);
            $routes->delete('sources/(:num)',        'Api\\V1\\Knowledge\\SourceController::destroy/$1', ['filter' => 'permission:source.manage']);
            $routes->post('sources/(:num)/submit',   'Api\\V1\\Knowledge\\SourceController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('sources/(:num)/approve',  'Api\\V1\\Knowledge\\SourceController::approve/$1', ['filter' => ['permission:source.approve', 'throttle:approval']]);
            $routes->post('sources/(:num)/reject',   'Api\\V1\\Knowledge\\SourceController::reject/$1',  ['filter' => ['permission:source.approve', 'throttle:approval']]);

            // Citations
            $routes->get('citations',                  'Api\\V1\\Knowledge\\CitationController::index',      ['filter' => 'permission:citation.view']);
            $routes->post('citations',                 'Api\\V1\\Knowledge\\CitationController::store',      ['filter' => 'permission:citation.manage']);
            $routes->get('citations/(:num)',           'Api\\V1\\Knowledge\\CitationController::show/$1',    ['filter' => 'permission:citation.view']);
            $routes->put('citations/(:num)',           'Api\\V1\\Knowledge\\CitationController::update/$1',  ['filter' => 'permission:citation.manage']);
            $routes->delete('citations/(:num)',        'Api\\V1\\Knowledge\\CitationController::destroy/$1', ['filter' => 'permission:citation.manage']);
            $routes->post('citations/(:num)/submit',   'Api\\V1\\Knowledge\\CitationController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('citations/(:num)/approve',  'Api\\V1\\Knowledge\\CitationController::approve/$1', ['filter' => ['permission:citation.approve', 'throttle:approval']]);
            $routes->post('citations/(:num)/reject',   'Api\\V1\\Knowledge\\CitationController::reject/$1',  ['filter' => ['permission:citation.approve', 'throttle:approval']]);

            // Brand Rules
            $routes->get('brand-rules',                  'Api\\V1\\Knowledge\\BrandRuleController::index',      ['filter' => 'permission:brand_rules.view']);
            $routes->post('brand-rules',                 'Api\\V1\\Knowledge\\BrandRuleController::store',      ['filter' => 'permission:brand_rules.manage']);
            $routes->get('brand-rules/(:num)',           'Api\\V1\\Knowledge\\BrandRuleController::show/$1',    ['filter' => 'permission:brand_rules.view']);
            $routes->put('brand-rules/(:num)',           'Api\\V1\\Knowledge\\BrandRuleController::update/$1',  ['filter' => 'permission:brand_rules.manage']);
            $routes->delete('brand-rules/(:num)',        'Api\\V1\\Knowledge\\BrandRuleController::destroy/$1', ['filter' => 'permission:brand_rules.manage']);
            $routes->post('brand-rules/(:num)/submit',   'Api\\V1\\Knowledge\\BrandRuleController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('brand-rules/(:num)/approve',  'Api\\V1\\Knowledge\\BrandRuleController::approve/$1', ['filter' => ['permission:brand_rules.approve', 'throttle:approval']]);
            $routes->post('brand-rules/(:num)/reject',   'Api\\V1\\Knowledge\\BrandRuleController::reject/$1',  ['filter' => ['permission:brand_rules.approve', 'throttle:approval']]);

            // Content Policies
            $routes->get('content-policies',                  'Api\\V1\\Knowledge\\ContentPolicyController::index',      ['filter' => 'permission:content_policy.view']);
            $routes->post('content-policies',                 'Api\\V1\\Knowledge\\ContentPolicyController::store',      ['filter' => 'permission:content_policy.manage']);
            $routes->get('content-policies/(:num)',           'Api\\V1\\Knowledge\\ContentPolicyController::show/$1',    ['filter' => 'permission:content_policy.view']);
            $routes->put('content-policies/(:num)',           'Api\\V1\\Knowledge\\ContentPolicyController::update/$1',  ['filter' => 'permission:content_policy.manage']);
            $routes->delete('content-policies/(:num)',        'Api\\V1\\Knowledge\\ContentPolicyController::destroy/$1', ['filter' => 'permission:content_policy.manage']);
            $routes->post('content-policies/(:num)/submit',   'Api\\V1\\Knowledge\\ContentPolicyController::submit/$1',  ['filter' => 'permission:knowledge.submit']);
            $routes->post('content-policies/(:num)/approve',  'Api\\V1\\Knowledge\\ContentPolicyController::approve/$1', ['filter' => ['permission:content_policy.approve', 'throttle:approval']]);
            $routes->post('content-policies/(:num)/reject',   'Api\\V1\\Knowledge\\ContentPolicyController::reject/$1',  ['filter' => ['permission:content_policy.approve', 'throttle:approval']]);

        }); // end knowledge group

        // ═══════════════════════════════════════════════════════════════════════
        // Phase 2 — Unified Content Studio
        // ═══════════════════════════════════════════════════════════════════════

        // Content Items
        $routes->get('content/items',                              'Api\\V1\\Content\\ContentItemController::index',          ['filter' => 'permission:content.view']);
        $routes->post('content/items',                             'Api\\V1\\Content\\ContentItemController::create',         ['filter' => 'permission:content.create']);
        $routes->get('content/items/(:num)',                       'Api\\V1\\Content\\ContentItemController::show/$1',        ['filter' => 'permission:content.view']);
        $routes->put('content/items/(:num)',                       'Api\\V1\\Content\\ContentItemController::update/$1',      ['filter' => 'permission:content.edit']);
        $routes->delete('content/items/(:num)',                    'Api\\V1\\Content\\ContentItemController::delete/$1',      ['filter' => 'permission:content.edit']);
        $routes->post('content/items/(:num)/submit',               'Api\\V1\\Content\\ContentItemController::submit/$1',      ['filter' => 'permission:content.submit']);
        $routes->post('content/items/(:num)/approve',              'Api\\V1\\Content\\ContentItemController::approve/$1',     ['filter' => ['permission:content.approve', 'throttle:approval']]);
        $routes->post('content/items/(:num)/reject',               'Api\\V1\\Content\\ContentItemController::reject/$1',      ['filter' => ['permission:content.approve', 'throttle:approval']]);
        $routes->post('content/items/(:num)/request-changes',      'Api\\V1\\Content\\ContentItemController::requestChanges/$1', ['filter' => 'permission:content.review']);
        $routes->post('content/items/(:num)/archive',              'Api\\V1\\Content\\ContentItemController::archive/$1',    ['filter' => 'permission:content.archive']);
        $routes->get('content/items/(:num)/transitions',           'Api\\V1\\Content\\ContentItemController::transitions/$1', ['filter' => 'permission:content.view']);
        $routes->post('content/items/(:num)/transition',           'Api\\V1\\Content\\ContentItemController::transition/$1',  ['filter' => 'permission:content.edit']);

        // Content Versions
        $routes->get('content/items/(:num)/versions',              'Api\\V1\\Content\\ContentVersionController::index/$1',   ['filter' => 'permission:content_version.view']);
        $routes->post('content/items/(:num)/versions',             'Api\\V1\\Content\\ContentVersionController::create/$1',  ['filter' => 'permission:content.edit']);
        $routes->get('content/items/(:num)/versions/compare',      'Api\\V1\\Content\\ContentVersionController::compare/$1', ['filter' => 'permission:content_version.view']);
        $routes->get('content/items/(:num)/versions/(:num)',       'Api\\V1\\Content\\ContentVersionController::show/$1/$2', ['filter' => 'permission:content_version.view']);

        // Content Brief
        $routes->get('content/items/(:num)/brief',                 'Api\\V1\\Content\\ContentBriefController::show/$1',    ['filter' => 'permission:content.view']);
        $routes->post('content/items/(:num)/brief',                'Api\\V1\\Content\\ContentBriefController::upsert/$1',  ['filter' => 'permission:content.edit']);
        $routes->put('content/items/(:num)/brief',                 'Api\\V1\\Content\\ContentBriefController::upsert/$1',  ['filter' => 'permission:content.edit']);

        // Content Comments
        $routes->get('content/items/(:num)/comments',              'Api\\V1\\Content\\ContentCommentController::index/$1',  ['filter' => 'permission:content_comment.view']);
        $routes->post('content/items/(:num)/comments',             'Api\\V1\\Content\\ContentCommentController::create/$1', ['filter' => 'permission:content_comment.create']);
        $routes->post('content/items/(:num)/comments/(:num)/resolve', 'Api\\V1\\Content\\ContentCommentController::resolve/$1/$2', ['filter' => 'permission:content_comment.resolve']);
        $routes->delete('content/items/(:num)/comments/(:num)',    'Api\\V1\\Content\\ContentCommentController::delete/$1/$2', ['filter' => 'permission:content_comment.delete']);

        // Content Validations
        $routes->get('content/items/(:num)/validations',           'Api\\V1\\Content\\ContentValidationController::index/$1',  ['filter' => 'permission:content_validation.view']);
        $routes->post('content/items/(:num)/validations',          'Api\\V1\\Content\\ContentValidationController::create/$1', ['filter' => 'permission:content_validation.manage']);
        $routes->post('content/items/(:num)/validations/(:num)/waive', 'Api\\V1\\Content\\ContentValidationController::waive/$1/$2', ['filter' => ['permission:content_validation.waive', 'throttle:approval']]);

        // Content Assignments
        $routes->get('content/items/(:num)/assignments',           'Api\\V1\\Content\\ContentAssignmentController::index/$1',  ['filter' => 'permission:content_assignment.view']);
        $routes->post('content/items/(:num)/assignments',          'Api\\V1\\Content\\ContentAssignmentController::create/$1', ['filter' => 'permission:content_assignment.manage']);
        $routes->delete('content/items/(:num)/assignments/(:num)', 'Api\\V1\\Content\\ContentAssignmentController::delete/$1/$2', ['filter' => 'permission:content_assignment.manage']);

        // Content Schedules
        $routes->get('content/items/(:num)/schedules',             'Api\\V1\\Content\\ContentScheduleController::index/$1',  ['filter' => 'permission:content_schedule.view']);
        $routes->post('content/items/(:num)/schedules',            'Api\\V1\\Content\\ContentScheduleController::create/$1', ['filter' => 'permission:content_schedule.create']);
        $routes->delete('content/items/(:num)/schedules/(:num)',   'Api\\V1\\Content\\ContentScheduleController::delete/$1/$2', ['filter' => 'permission:content_schedule.cancel']);

        // Content Knowledge Mappings
        $routes->get('content/items/(:num)/mappings',              'Api\\V1\\Content\\ContentMappingController::index/$1',   ['filter' => 'permission:content.view']);
        $routes->put('content/items/(:num)/mappings',              'Api\\V1\\Content\\ContentMappingController::sync/$1',    ['filter' => 'permission:content.edit']);
        $routes->post('content/items/(:num)/mappings/(:alpha)',    'Api\\V1\\Content\\ContentMappingController::addMapping/$1/$2', ['filter' => 'permission:content.edit']);
        $routes->delete('content/items/(:num)/mappings/(:alpha)/(:num)', 'Api\\V1\\Content\\ContentMappingController::removeMapping/$1/$2/$3', ['filter' => 'permission:content.edit']);

        // Publication Targets
        $routes->get('content/publication-targets',                'Api\\V1\\Content\\ContentPublicationTargetController::index',      ['filter' => 'permission:publication_target.view']);
        $routes->post('content/publication-targets',               'Api\\V1\\Content\\ContentPublicationTargetController::create',     ['filter' => 'permission:publication_target.manage']);
        $routes->get('content/publication-targets/(:num)',         'Api\\V1\\Content\\ContentPublicationTargetController::show/$1',    ['filter' => 'permission:publication_target.view']);
        $routes->put('content/publication-targets/(:num)',         'Api\\V1\\Content\\ContentPublicationTargetController::update/$1',  ['filter' => 'permission:publication_target.manage']);

        // Daily Marketing Packs
        $routes->get('content/daily-packs',                        'Api\\V1\\Content\\DailyPackController::index',              ['filter' => 'permission:daily_pack.view']);
        $routes->post('content/daily-packs/generate',              'Api\\V1\\Content\\DailyPackController::generate',           ['filter' => 'permission:daily_pack.create']);
        $routes->get('content/daily-packs/config',                 'Api\\V1\\Content\\DailyPackController::getConfig',          ['filter' => 'permission:daily_pack.view']);
        $routes->put('content/daily-packs/config',                 'Api\\V1\\Content\\DailyPackController::updateConfig',       ['filter' => 'permission:daily_pack.manage']);
        $routes->get('content/daily-packs/(:num)',                 'Api\\V1\\Content\\DailyPackController::show/$1',             ['filter' => 'permission:daily_pack.view']);
        $routes->put('content/daily-packs/(:num)/items/(:num)',    'Api\\V1\\Content\\DailyPackController::assignItem/$1/$2',    ['filter' => 'permission:daily_pack.manage']);

        // Approval Queue
        $routes->get('approval-queue',                             'Api\\V1\\Content\\ApprovalQueueController::index',           ['filter' => 'permission:content.review']);
        $routes->get('approval-queue/stats',                       'Api\\V1\\Content\\ApprovalQueueController::stats',           ['filter' => 'permission:content.review']);
        $routes->post('approval-queue/bulk-approve',               'Api\\V1\\Content\\ApprovalQueueController::bulkApprove',     ['filter' => ['permission:content.approve', 'throttle:approval']]);
        $routes->post('approval-queue/(:num)/approve',             'Api\\V1\\Content\\ApprovalQueueController::approve/$1',      ['filter' => ['permission:content.approve', 'throttle:approval']]);
        $routes->post('approval-queue/(:num)/reject',              'Api\\V1\\Content\\ApprovalQueueController::reject/$1',       ['filter' => ['permission:content.approve', 'throttle:approval']]);
        $routes->post('approval-queue/(:num)/return',              'Api\\V1\\Content\\ApprovalQueueController::returnForChanges/$1', ['filter' => 'permission:content.review']);
        $routes->post('approval-queue/(:num)/waive-validation',    'Api\\V1\\Content\\ApprovalQueueController::waiveValidation/$1', ['filter' => ['permission:content_validation.waive', 'throttle:approval']]);

        // Notifications
        $routes->get('notifications',                              'Api\\V1\\Content\\NotificationController::index',           ['filter' => 'permission:content.view']);
        $routes->get('notifications/count',                        'Api\\V1\\Content\\NotificationController::count',           ['filter' => 'permission:content.view']);
        $routes->post('notifications/(:num)/read',                 'Api\\V1\\Content\\NotificationController::markRead/$1',     ['filter' => 'permission:content.view']);
        $routes->post('notifications/read-all',                    'Api\\V1\\Content\\NotificationController::markAllRead',     ['filter' => 'permission:content.view']);

        // --- Phase 3: AI Generation ---
        $routes->post('ai/generate',                                        'Api\\V1\\Ai\\AiGenerationController::generate',               ['filter' => 'permission:ai.generate']);
        $routes->get('ai/generations',                                      'Api\\V1\\Ai\\AiGenerationController::index',                  ['filter' => 'permission:ai.view']);
        $routes->get('ai/generations/(:segment)',                           'Api\\V1\\Ai\\AiGenerationController::show/$1',                ['filter' => 'permission:ai.view']);
        $routes->post('ai/generations/(:segment)/cancel',                   'Api\\V1\\Ai\\AiGenerationController::cancel/$1',              ['filter' => 'permission:ai.generate']);

        // --- Phase 3: AI Prompt Governance ---
        $routes->get('ai/prompts/schema-types',                            'Api\\V1\\Ai\\PromptController::schemaTypes',                  ['filter' => 'permission:ai_prompt.view']);
        $routes->get('ai/prompts',                                          'Api\\V1\\Ai\\PromptController::index',                        ['filter' => 'permission:ai_prompt.view']);
        $routes->post('ai/prompts',                                         'Api\\V1\\Ai\\PromptController::create',                       ['filter' => 'permission:ai_prompt.manage']);
        $routes->get('ai/prompts/(:segment)',                               'Api\\V1\\Ai\\PromptController::show/$1',                      ['filter' => 'permission:ai_prompt.view']);
        $routes->get('ai/prompts/(:num)/versions',                          'Api\\V1\\Ai\\PromptController::listVersions/$1',              ['filter' => 'permission:ai_prompt.view']);
        $routes->post('ai/prompts/(:num)/versions',                         'Api\\V1\\Ai\\PromptController::createVersion/$1',             ['filter' => 'permission:ai_prompt.manage']);
        $routes->post('ai/prompts/(:num)/versions/(:num)/approve',          'Api\\V1\\Ai\\PromptController::approveVersion/$1/$2',         ['filter' => 'permission:ai_prompt.approve']);

        // --- Phase 3: AI Control Centre — Dashboard & Health ---
        $routes->get('ai/dashboard',                                        'Api\\V1\\Ai\\AiDashboardController::dashboard',               ['filter' => 'permission:ai.view']);
        $routes->get('ai/health',                                           'Api\\V1\\Ai\\AiDashboardController::health',                  ['filter' => 'permission:ai_provider.manage']);

        // --- Phase 3: AI Providers ---
        $routes->get('ai/providers',                                        'Api\\V1\\Ai\\AiProviderController::index',                    ['filter' => 'permission:ai_provider.manage']);
        $routes->get('ai/providers/(:num)',                                 'Api\\V1\\Ai\\AiProviderController::show/$1',                  ['filter' => 'permission:ai_provider.manage']);
        $routes->patch('ai/providers/(:num)/status',                       'Api\\V1\\Ai\\AiProviderController::updateStatus/$1',          ['filter' => 'permission:ai_provider.manage']);

        // --- Phase 3: AI Models ---
        $routes->get('ai/models',                                           'Api\\V1\\Ai\\AiModelController::index',                       ['filter' => 'permission:ai_provider.manage']);

        // --- Phase 3: AI Usage & Budgets ---
        $routes->get('ai/usage',                                            'Api\\V1\\Ai\\AiUsageController::usage',                       ['filter' => 'permission:ai_provider.manage']);
        $routes->get('ai/budgets',                                          'Api\\V1\\Ai\\AiUsageController::budgets',                     ['filter' => 'permission:ai_provider.manage']);
        $routes->put('ai/budgets/(:num)',                                   'Api\\V1\\Ai\\AiUsageController::updateBudget/$1',             ['filter' => 'permission:ai_provider.manage']);

        // --- Phase 4: Publishing ---
        $routes->get('publishing/blogs',                                    'Api\\V1\\Publishing\\BlogPublishingController::index',                    ['filter' => 'permission:publishing.view']);
        $routes->get('publishing/knowledge-bases',                          'Api\\V1\\Publishing\\KbPublishingController::index',                      ['filter' => 'permission:publishing.view']);
        $routes->get('publishing/calendar',                                 'Api\\V1\\Publishing\\PublishingCalendarController::index',                 ['filter' => 'permission:publishing.view']);
        $routes->get('publishing/deployments',                              'Api\\V1\\Publishing\\DeploymentController::index',                        ['filter' => 'permission:publishing.view']);
        $routes->get('publishing/deployments/(:num)',                       'Api\\V1\\Publishing\\DeploymentController::show/$1',                      ['filter' => 'permission:publishing.view']);
        $routes->post('publishing/deployments/(:num)/retry',                'Api\\V1\\Publishing\\DeploymentController::retry/$1',                     ['filter' => 'permission:publishing.publish']);
        $routes->post('publishing/deployments/(:num)/cancel',               'Api\\V1\\Publishing\\DeploymentController::cancel/$1',                    ['filter' => 'permission:publishing.publish']);
        $routes->post('publishing/deployments/(:num)/verify',               'Api\\V1\\Publishing\\DeploymentController::verify/$1',                    ['filter' => 'permission:publishing.publish']);
        $routes->post('publishing/deployments/(:num)/rollback',             'Api\\V1\\Publishing\\DeploymentController::rollback/$1',                  ['filter' => 'permission:publishing.rollback']);
        $routes->get('publishing/deployments/(:num)/verifications',         'Api\\V1\\Publishing\\DeploymentController::verifications/$1',             ['filter' => 'permission:publishing.view']);
        $routes->get('publishing/verifications',                            'Api\\V1\\Publishing\\VerificationController::index',                      ['filter' => 'permission:publishing.view']);
        $routes->get('publishing/connections',                              'Api\\V1\\Publishing\\ConnectionController::index',                        ['filter' => 'permission:publishing.manage_connections']);
        $routes->post('publishing/connections/(:segment)/health-check',     'Api\\V1\\Publishing\\ConnectionController::healthCheck/$1',               ['filter' => 'permission:publishing.manage_connections']);
        $routes->get('publishing/readiness/(:num)',                         'Api\\V1\\Publishing\\ReadinessController::evaluate/$1',                   ['filter' => 'permission:publishing.view']);
        $routes->get('publishing/seo/(:num)',                               'Api\\V1\\Publishing\\SeoProfileController::show/$1',                      ['filter' => 'permission:seo.view']);
        $routes->put('publishing/seo/(:num)',                               'Api\\V1\\Publishing\\SeoProfileController::update/$1',                    ['filter' => 'permission:seo.manage']);
        $routes->post('publishing/seo/(:num)/evaluate',                     'Api\\V1\\Publishing\\SeoProfileController::evaluate/$1',                  ['filter' => 'permission:seo.manage']);
        $routes->post('publishing/content/(:num)/publish',                  'Api\\V1\\Publishing\\ContentPublishController::publish/$1',               ['filter' => 'permission:publishing.publish']);
        $routes->post('publishing/content/(:num)/schedule',                 'Api\\V1\\Publishing\\ContentPublishController::schedule/$1',              ['filter' => 'permission:publishing.publish']);
        $routes->post('publishing/content/(:num)/unpublish',                'Api\\V1\\Publishing\\ContentPublishController::unpublish/$1',             ['filter' => 'permission:publishing.publish']);

        // --- Phase 5: Community Q&A ---
        // All filter slugs use canonical two-segment format: group.action
        // Spaces
        $routes->get('community/spaces',                                     'Api\\V1\\Community\\CommunitySpaceController::index',                     ['filter' => 'permission:community.view']);
        $routes->post('community/spaces',                                    'Api\\V1\\Community\\CommunitySpaceController::create',                    ['filter' => 'permission:community_settings.manage']);
        $routes->get('community/spaces/(:segment)',                          'Api\\V1\\Community\\CommunitySpaceController::show/$1',                   ['filter' => 'permission:community.view']);
        $routes->put('community/spaces/(:segment)',                          'Api\\V1\\Community\\CommunitySpaceController::update/$1',                 ['filter' => 'permission:community_settings.manage']);

        // Questions
        $routes->get('community/questions',                                  'Api\\V1\\Community\\QuestionController::index',                           ['filter' => 'permission:community.view']);
        $routes->post('community/questions',                                 'Api\\V1\\Community\\QuestionController::create',                          ['filter' => 'permission:community_intake.create']);
        $routes->get('community/questions/stats',                            'Api\\V1\\Community\\QuestionController::stats',                           ['filter' => 'permission:community.view']);
        $routes->get('community/questions/(:segment)',                       'Api\\V1\\Community\\QuestionController::show/$1',                         ['filter' => 'permission:community.view']);
        $routes->put('community/questions/(:segment)/status',                'Api\\V1\\Community\\QuestionController::updateStatus/$1',                 ['filter' => 'permission:community_question.edit']);

        // Official Identities
        $routes->get('community/identities',                                 'Api\\V1\\Community\\OfficialIdentityController::index',                   ['filter' => 'permission:community.view']);
        $routes->post('community/identities',                                'Api\\V1\\Community\\OfficialIdentityController::create',                  ['filter' => 'permission:community_identity.manage']);
        $routes->get('community/identities/(:segment)',                      'Api\\V1\\Community\\OfficialIdentityController::show/$1',                 ['filter' => 'permission:community.view']);
        $routes->put('community/identities/(:segment)',                      'Api\\V1\\Community\\OfficialIdentityController::update/$1',               ['filter' => 'permission:community_identity.manage']);
        $routes->delete('community/identities/(:segment)',                   'Api\\V1\\Community\\OfficialIdentityController::deactivate/$1',           ['filter' => 'permission:community_identity.manage']);

        // Official Answers
        $routes->get('community/answers',                                    'Api\\V1\\Community\\OfficialAnswerController::index',                     ['filter' => 'permission:community.view']);
        $routes->post('community/answers',                                   'Api\\V1\\Community\\OfficialAnswerController::create',                    ['filter' => 'permission:community_answer.generate']);
        $routes->get('community/answers/(:segment)',                         'Api\\V1\\Community\\OfficialAnswerController::show/$1',                   ['filter' => 'permission:community.view']);
        $routes->put('community/answers/(:segment)',                         'Api\\V1\\Community\\OfficialAnswerController::update/$1',                 ['filter' => 'permission:community_answer.edit']);
        $routes->get('community/answers/(:segment)/versions',                'Api\\V1\\Community\\OfficialAnswerController::versions/$1',               ['filter' => 'permission:community.view']);
        $routes->post('community/answers/(:segment)/generate',               'Api\\V1\\Community\\OfficialAnswerController::generate/$1',               ['filter' => 'permission:community_answer.generate']);
        $routes->post('community/answers/(:segment)/approve',                'Api\\V1\\Community\\OfficialAnswerController::approve/$1',                ['filter' => 'permission:community_answer.approve']);
        $routes->post('community/answers/(:segment)/reject',                 'Api\\V1\\Community\\OfficialAnswerController::reject/$1',                 ['filter' => 'permission:community_answer.approve']);
        $routes->post('community/answers/(:segment)/publish',                'Api\\V1\\Community\\OfficialAnswerController::publish/$1',                ['filter' => 'permission:community_answer.publish']);
        $routes->post('community/answers/(:segment)/withdraw',               'Api\\V1\\Community\\OfficialAnswerController::withdraw/$1',               ['filter' => 'permission:community_answer.withdraw']);
        $routes->post('community/answers/(:segment)/restore',                'Api\\V1\\Community\\OfficialAnswerController::restore/$1',                ['filter' => 'permission:community_answer.restore']);
        $routes->post('community/answers/(:segment)/correct',                'Api\\V1\\Community\\OfficialAnswerController::correct/$1',                ['filter' => 'permission:community_answer.edit']);

        // Moderation
        $routes->get('community/moderation/queue',                           'Api\\V1\\Community\\CommunityModerationController::queue',                ['filter' => 'permission:community_question.moderate']);
        $routes->post('community/moderation/(:num)/resolve',                 'Api\\V1\\Community\\CommunityModerationController::resolve/$1',           ['filter' => 'permission:community_question.moderate']);
        $routes->post('community/moderation/(:num)/escalate',                'Api\\V1\\Community\\CommunityModerationController::escalate/$1',          ['filter' => 'permission:community_question.moderate']);
        $routes->post('community/answers/(:segment)/run-moderation',         'Api\\V1\\Community\\CommunityModerationController::runModeration/$1',     ['filter' => 'permission:community_question.moderate']);

        // Deployments
        $routes->get('community/deployments',                                'Api\\V1\\Community\\CommunityDeploymentController::index',                ['filter' => 'permission:community.view']);
        $routes->get('community/deployments/(:segment)',                     'Api\\V1\\Community\\CommunityDeploymentController::show/$1',              ['filter' => 'permission:community.view']);
        $routes->post('community/deployments/(:segment)/retry',              'Api\\V1\\Community\\CommunityDeploymentController::retry/$1',             ['filter' => 'permission:community_answer.publish']);
        $routes->post('community/deployments/(:segment)/verify',             'Api\\V1\\Community\\CommunityDeploymentController::verify/$1',            ['filter' => 'permission:community_answer.publish']);

        // Analytics
        $routes->get('community/analytics/overview',                         'Api\\V1\\Community\\CommunityAnalyticsController::overview',              ['filter' => 'permission:community_analytics.view']);
        $routes->get('community/analytics/engagement',                       'Api\\V1\\Community\\CommunityAnalyticsController::engagement',            ['filter' => 'permission:community_analytics.view']);
        $routes->get('community/analytics/coverage',                         'Api\\V1\\Community\\CommunityAnalyticsController::sourceCoverage',        ['filter' => 'permission:community_analytics.view']);
        $routes->get('community/analytics/cache',                            'Api\\V1\\Community\\CommunityAnalyticsController::cache',                 ['filter' => 'permission:community_analytics.view']);

        // ─────────────────────────────────────────────────────────────────────
        // Phase 6 — Video Content Automation
        // ─────────────────────────────────────────────────────────────────────

        // Ideas
        $routes->get('video/ideas',                                          'Api\\V1\\Video\\VideoIdeaController::index',                              ['filter' => 'permission:video.read']);
        $routes->post('video/ideas',                                         'Api\\V1\\Video\\VideoIdeaController::store',                              ['filter' => 'permission:video.create']);
        $routes->get('video/ideas/(:segment)',                               'Api\\V1\\Video\\VideoIdeaController::show/$1',                            ['filter' => 'permission:video.read']);
        $routes->put('video/ideas/(:segment)',                               'Api\\V1\\Video\\VideoIdeaController::update/$1',                          ['filter' => 'permission:video.update']);
        $routes->post('video/ideas/(:segment)/accept',                       'Api\\V1\\Video\\VideoIdeaController::accept/$1',                          ['filter' => 'permission:video.update']);
        $routes->post('video/ideas/(:segment)/reject',                       'Api\\V1\\Video\\VideoIdeaController::reject/$1',                          ['filter' => 'permission:video.update']);
        $routes->post('video/ideas/(:segment)/convert',                      'Api\\V1\\Video\\VideoIdeaController::convert/$1',                         ['filter' => 'permission:video.create']);
        $routes->post('video/ideas/(:segment)/sources',                      'Api\\V1\\Video\\VideoIdeaController::addSource/$1',                       ['filter' => 'permission:video.update']);

        // Projects
        $routes->get('video/projects',                                       'Api\\V1\\Video\\VideoProjectController::index',                           ['filter' => 'permission:video.read']);
        $routes->post('video/projects',                                      'Api\\V1\\Video\\VideoProjectController::store',                           ['filter' => 'permission:video.create']);
        $routes->get('video/projects/(:segment)',                            'Api\\V1\\Video\\VideoProjectController::show/$1',                         ['filter' => 'permission:video.read']);
        $routes->put('video/projects/(:segment)',                            'Api\\V1\\Video\\VideoProjectController::update/$1',                       ['filter' => 'permission:video.update']);
        $routes->post('video/projects/(:segment)/cancel',                    'Api\\V1\\Video\\VideoProjectController::cancel/$1',                       ['filter' => 'permission:video.cancel']);

        // Script lifecycle (CP4+)
        $routes->get('video/projects/(:segment)/script',                     'Api\\V1\\Video\\VideoScriptController::show/$1',                          ['filter' => 'permission:video.read']);
        $routes->post('video/projects/(:segment)/script',                    'Api\\V1\\Video\\VideoScriptController::store/$1',                         ['filter' => 'permission:video.create']);
        $routes->post('video/projects/(:segment)/script/generate',           'Api\\V1\\Video\\VideoScriptController::generate/$1',                      ['filter' => 'permission:video.generate']);
        $routes->post('video/projects/(:segment)/script/submit',             'Api\\V1\\Video\\VideoScriptController::submit/$1',                        ['filter' => 'permission:video.submit']);
        $routes->post('video/projects/(:segment)/script/approve',            'Api\\V1\\Video\\VideoScriptController::approve/$1',                       ['filter' => 'permission:video.approve']);
        $routes->post('video/projects/(:segment)/script/reject',             'Api\\V1\\Video\\VideoScriptController::reject/$1',                        ['filter' => 'permission:video.review']);
        $routes->post('video/projects/(:segment)/script/request-changes',    'Api\\V1\\Video\\VideoScriptController::requestChanges/$1',                ['filter' => 'permission:video.review']);
        $routes->get('video/projects/(:segment)/script/versions',            'Api\\V1\\Video\\VideoScriptController::versions/$1',                      ['filter' => 'permission:video.read']);
        $routes->get('video/projects/(:segment)/script/versions/(:num)',     'Api\\V1\\Video\\VideoScriptController::versionDetail/$1/$2',              ['filter' => 'permission:video.read']);

        // Assets (CP6)
        $routes->get('video/projects/(:segment)/assets',                     'Api\\V1\\Video\\VideoAssetController::listForProject/$1',                 ['filter' => 'permission:video.read']);
        $routes->post('video/projects/(:segment)/assets',                    'Api\\V1\\Video\\VideoAssetController::upload/$1',                         ['filter' => 'permission:video.create']);
        $routes->get('video/assets/(:segment)',                              'Api\\V1\\Video\\VideoAssetController::show/$1',                            ['filter' => 'permission:video.read']);

        // Render (CP6)
        $routes->post('video/projects/(:segment)/render',                    'Api\\V1\\Video\\VideoRenderController::queue/$1',                         ['filter' => 'permission:video.render']);
        $routes->get('video/render-jobs/(:segment)',                         'Api\\V1\\Video\\VideoRenderController::showJob/$1',                        ['filter' => 'permission:video.read']);
        $routes->delete('video/render-jobs/(:segment)',                      'Api\\V1\\Video\\VideoRenderController::cancel/$1',                         ['filter' => 'permission:video.cancel']);
        $routes->post('video/render-jobs/(:segment)/retry',                  'Api\\V1\\Video\\VideoRenderController::retry/$1',                          ['filter' => 'permission:video.retry']);

        // Render profiles (CP6)
        $routes->get('video/render-profiles',                                'Api\\V1\\Video\\VideoRenderController::listProfiles',                      ['filter' => 'permission:video.read']);
        $routes->post('video/render-profiles',                               'Api\\V1\\Video\\VideoRenderController::createProfile',                     ['filter' => 'permission:video.render']);
        $routes->get('video/render-profiles/(:segment)',                     'Api\\V1\\Video\\VideoRenderController::showProfile/$1',                    ['filter' => 'permission:video.read']);
        $routes->put('video/render-profiles/(:segment)',                     'Api\\V1\\Video\\VideoRenderController::updateProfile/$1',                  ['filter' => 'permission:video.render']);
        $routes->delete('video/render-profiles/(:segment)',                  'Api\\V1\\Video\\VideoRenderController::deleteProfile/$1',                  ['filter' => 'permission:video.render']);

        // YouTube publication (CP8)
        $routes->get('video/projects/(:segment)/publish',                    'Api\\V1\\Video\\VideoPublicationController::show/$1',                      ['filter' => 'permission:video.read']);
        $routes->post('video/projects/(:segment)/publish',                   'Api\\V1\\Video\\VideoPublicationController::publish/$1',                   ['filter' => 'permission:video.publish']);
        $routes->post('video/projects/(:segment)/publish/retry',             'Api\\V1\\Video\\VideoPublicationController::retry/$1',                     ['filter' => 'permission:video.retry']);
        $routes->post('video/projects/(:segment)/publish/cancel',            'Api\\V1\\Video\\VideoPublicationController::cancel/$1',                    ['filter' => 'permission:video.cancel']);

        // YouTube connections (CP8)
        $routes->get('video/connections',                                    'Api\\V1\\Video\\VideoConnectionController::index',                          ['filter' => 'permission:video_connections.read']);
        $routes->post('video/connections',                                   'Api\\V1\\Video\\VideoConnectionController::store',                          ['filter' => 'permission:video_connections.manage']);
        $routes->get('video/connections/(:segment)',                         'Api\\V1\\Video\\VideoConnectionController::show/$1',                        ['filter' => 'permission:video_connections.read']);
        $routes->delete('video/connections/(:segment)',                      'Api\\V1\\Video\\VideoConnectionController::revoke/$1',                      ['filter' => 'permission:video_connections.manage']);
        $routes->get('video/connections/(:segment)/health',                  'Api\\V1\\Video\\VideoConnectionController::health/$1',                      ['filter' => 'permission:video_connections.read']);

        // Publications list + operations (CP8)
        $routes->get('video/publications',                                   'Api\\V1\\Video\\VideoPublicationController::list',                          ['filter' => 'permission:video.read']);
        $routes->get('video/operations',                                     'Api\\V1\\Video\\VideoOperationsController::index',                          ['filter' => 'permission:video_operations.read']);

        // Audit (CP8)
        $routes->get('video/projects/(:segment)/audit',                      'Api\\V1\\Video\\VideoOperationsController::auditForProject/$1',             ['filter' => 'permission:video_audit.read']);

        // Provider callbacks (CP7 — unauthenticated, HMAC-verified)
        $routes->post('video/provider/render-callback',                      'Api\\V1\\Video\\VideoProviderCallbackController::renderCallback',           ['filter' => 'noauth']);
        $routes->post('video/provider/youtube-callback',                     'Api\\V1\\Video\\VideoProviderCallbackController::youtubeCallback',          ['filter' => 'noauth']);

        // ── Phase 7: Distribution ─────────────────────────────────────────────

        // Audience segments (CP3)
        $routes->get('distribution/segments',                                'Api\\V1\\Distribution\\AudienceSegmentController::index',    ['filter' => 'permission:distribution.read']);
        $routes->post('distribution/segments',                               'Api\\V1\\Distribution\\AudienceSegmentController::store',    ['filter' => 'permission:distribution.segment']);
        $routes->get('distribution/segments/(:segment)',                     'Api\\V1\\Distribution\\AudienceSegmentController::show/$1',  ['filter' => 'permission:distribution.read']);
        $routes->put('distribution/segments/(:segment)',                     'Api\\V1\\Distribution\\AudienceSegmentController::update/$1',['filter' => 'permission:distribution.segment']);
        $routes->delete('distribution/segments/(:segment)',                  'Api\\V1\\Distribution\\AudienceSegmentController::destroy/$1',['filter' => 'permission:distribution.segment']);
        $routes->post('distribution/segments/(:segment)/preview',            'Api\\V1\\Distribution\\AudienceSegmentController::preview/$1',['filter' => 'permission:distribution.preview']);

        // Consents (CP3)
        $routes->get('distribution/consents',                                'Api\\V1\\Distribution\\ConsentController::index',            ['filter' => 'permission:distribution.consent.read']);
        $routes->post('distribution/consents',                               'Api\\V1\\Distribution\\ConsentController::store',            ['filter' => 'permission:distribution.consent.manage']);
        $routes->delete('distribution/consents/(:num)',                      'Api\\V1\\Distribution\\ConsentController::destroy/$1',       ['filter' => 'permission:distribution.consent.manage']);

        // Suppressions (CP3)
        $routes->get('distribution/suppressions',                            'Api\\V1\\Distribution\\SuppressionController::index',        ['filter' => 'permission:distribution.suppression.read']);
        $routes->post('distribution/suppressions',                           'Api\\V1\\Distribution\\SuppressionController::store',        ['filter' => 'permission:distribution.suppression.manage']);
        $routes->delete('distribution/suppressions/(:num)',                  'Api\\V1\\Distribution\\SuppressionController::destroy/$1',   ['filter' => 'permission:distribution.suppression.manage']);

        // Audience snapshots (CP3)
        $routes->get('distribution/campaigns/(:num)/audience-snapshot',      'Api\\V1\\Distribution\\AudienceSnapshotController::show/$1',  ['filter' => 'permission:distribution.read']);
        $routes->post('distribution/campaigns/(:num)/audience-snapshot',     'Api\\V1\\Distribution\\AudienceSnapshotController::create/$1',['filter' => 'permission:distribution.segment']);
        $routes->post('distribution/campaigns/(:num)/audience-snapshot/freeze','Api\\V1\\Distribution\\AudienceSnapshotController::freeze/$1',['filter' => 'permission:distribution.approve']);

        // Campaign versions (CP4)
        $routes->get('campaigns/(:num)/versions',                            'Api\\V1\\Distribution\\CampaignVersionController::index/$1',         ['filter' => 'permission:distribution.read']);
        $routes->post('campaigns/(:num)/versions',                           'Api\\V1\\Distribution\\CampaignVersionController::store/$1',         ['filter' => 'permission:distribution.create']);
        $routes->get('campaigns/(:num)/versions/(:num)',                     'Api\\V1\\Distribution\\CampaignVersionController::show/$1/$2',        ['filter' => 'permission:distribution.read']);
        $routes->post('campaigns/(:num)/versions/(:num)/submit',             'Api\\V1\\Distribution\\CampaignVersionController::submit/$1/$2',      ['filter' => 'permission:distribution.submit']);
        $routes->post('campaigns/(:num)/versions/(:num)/approve',            'Api\\V1\\Distribution\\CampaignVersionController::approve/$1/$2',     ['filter' => 'permission:distribution.approve']);
        $routes->post('campaigns/(:num)/versions/(:num)/reject',             'Api\\V1\\Distribution\\CampaignVersionController::reject/$1/$2',      ['filter' => 'permission:distribution.review']);
        $routes->post('campaigns/(:num)/versions/(:num)/request-changes',    'Api\\V1\\Distribution\\CampaignVersionController::requestChanges/$1/$2',['filter' => 'permission:distribution.review']);
        $routes->get('campaigns/(:num)/versions/(:num)/variants',            'Api\\V1\\Distribution\\CampaignVersionController::variants/$1/$2',    ['filter' => 'permission:distribution.read']);
        $routes->post('campaigns/(:num)/versions/(:num)/variants',           'Api\\V1\\Distribution\\CampaignVersionController::storeVariant/$1/$2',['filter' => 'permission:distribution.create']);

        // Channel variants (CP4)
        $routes->put('distribution/variants/(:num)',                         'Api\\V1\\Distribution\\ChannelVariantController::update/$1',          ['filter' => 'permission:distribution.update']);
        $routes->post('distribution/variants/(:num)/validate',               'Api\\V1\\Distribution\\ChannelVariantController::validate/$1',        ['filter' => 'permission:distribution.update']);

        // Social dispatch (CP5)
        $routes->post('distribution/social/dispatch/(:num)',                 'Api\\V1\\Distribution\\SocialDispatchController::dispatch/$1',        ['filter' => 'permission:distribution.dispatch']);
        $routes->get('distribution/social/status/(:num)',                    'Api\\V1\\Distribution\\SocialDispatchController::status/$1',          ['filter' => 'permission:distribution.read']);

        // Email dispatch (CP6)
        $routes->post('distribution/email/dispatch/(:num)',                  'Api\\V1\\Distribution\\EmailDispatchController::dispatch/$1',         ['filter' => 'permission:distribution.dispatch']);
        $routes->get('distribution/email/status/(:num)',                     'Api\\V1\\Distribution\\EmailDispatchController::status/$1',           ['filter' => 'permission:distribution.read']);
        $routes->post('distribution/email/test/(:num)',                      'Api\\V1\\Distribution\\EmailDispatchController::test/$1',             ['filter' => 'permission:distribution.dispatch']);

    });
});
