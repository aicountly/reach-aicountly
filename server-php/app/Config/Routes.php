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
    });
});
