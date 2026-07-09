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

$routes->group('v1', static function ($routes) {

    // -----------------------------------------------------------------------
    // Public auth (no JWT). Console SSO is the sole sign-in path.
    // -----------------------------------------------------------------------
    $routes->post('auth/login',          'Api\\V1\\AuthController::login');
    $routes->post('auth/refresh',        'Api\\V1\\AuthController::refresh');
    $routes->get('auth/sso-callback',    'Api\\V1\\AuthController::ssoCallback');
    $routes->post('auth/controller-sso', 'Api\\V1\\AuthController::controllerSso');
    $routes->post('auth/console-session', 'Api\\V1\\AuthController::consoleSession');

    // -----------------------------------------------------------------------
    // Public lead capture (form/landing/embed). Signed with a rotating token
    // from PUBLIC_LEAD_CAPTURE_TOKEN in .env. Not JWT-authenticated.
    // -----------------------------------------------------------------------
    $routes->group('public', ['filter' => 'public-capture'], static function ($routes) {
        $routes->post('leads/capture', 'Api\\V1\\LeadController::publicCapture');
    });

    // -----------------------------------------------------------------------
    // Inbound from Console (X-Console-Token). Console orchestrates approvals
    // and mode switches; Reach exposes callback endpoints so Console can
    // drive without needing a user session.
    // -----------------------------------------------------------------------
    $routes->group('portal', ['filter' => 'console-token'], static function ($routes) {
        $routes->get('bot/health',          'Api\\V1\\Portal\\BotController::health');
        $routes->get('bot/mode',            'Api\\V1\\Portal\\BotController::getMode');
        $routes->put('bot/mode',            'Api\\V1\\Portal\\BotController::setMode');
        $routes->post('bot/approval-callback', 'Api\\V1\\Portal\\BotController::approvalCallback');
    });

    // -----------------------------------------------------------------------
    // Authenticated + super_admin only. Every Reach admin surface is here.
    // -----------------------------------------------------------------------
    $routes->group('', ['filter' => ['jwt', 'super-admin']], static function ($routes) {
        $routes->get('me',           'Api\\V1\\AuthController::me');
        $routes->post('auth/logout', 'Api\\V1\\AuthController::logout');
        $routes->get('auth/controller-apps/launcher', 'Api\\V1\\AuthController::controllerAppsLauncher');
        $routes->get('auth/sso/launch-url', 'Api\\V1\\AuthController::ssoLaunchUrl');

        // Dashboard summary and per-module counts.
        $routes->get('dashboard/summary', 'Api\\V1\\DashboardController::summary');
        $routes->get('dashboard/counts',  'Api\\V1\\DashboardController::counts');

        // Blog management (with version history and workflow states).
        $routes->get('blog/posts',                'Api\\V1\\BlogController::index');
        $routes->post('blog/posts',               'Api\\V1\\BlogController::store');
        $routes->get('blog/posts/(:num)',         'Api\\V1\\BlogController::show/$1');
        $routes->put('blog/posts/(:num)',         'Api\\V1\\BlogController::update/$1');
        $routes->delete('blog/posts/(:num)',      'Api\\V1\\BlogController::destroy/$1');
        $routes->post('blog/posts/(:num)/transition', 'Api\\V1\\BlogController::transition/$1');
        $routes->post('blog/posts/(:num)/approve',    'Api\\V1\\BlogController::approve/$1');
        $routes->post('blog/posts/(:num)/reject',     'Api\\V1\\BlogController::reject/$1');
        $routes->post('blog/posts/(:num)/publish',    'Api\\V1\\BlogController::publish/$1');
        $routes->get('blog/posts/(:num)/versions',    'Api\\V1\\BlogVersionController::index/$1');
        $routes->get('blog/posts/(:num)/versions/(:num)', 'Api\\V1\\BlogVersionController::show/$1/$2');

        // Content calendar
        $routes->get('calendar/items',        'Api\\V1\\ContentCalendarController::index');
        $routes->post('calendar/items',       'Api\\V1\\ContentCalendarController::store');
        $routes->put('calendar/items/(:num)', 'Api\\V1\\ContentCalendarController::update/$1');
        $routes->delete('calendar/items/(:num)', 'Api\\V1\\ContentCalendarController::destroy/$1');

        // Campaigns
        $routes->get('campaigns',                 'Api\\V1\\CampaignController::index');
        $routes->post('campaigns',                'Api\\V1\\CampaignController::store');
        $routes->get('campaigns/(:num)',          'Api\\V1\\CampaignController::show/$1');
        $routes->put('campaigns/(:num)',          'Api\\V1\\CampaignController::update/$1');
        $routes->delete('campaigns/(:num)',       'Api\\V1\\CampaignController::destroy/$1');
        $routes->post('campaigns/(:num)/approve', 'Api\\V1\\CampaignController::approve/$1');
        $routes->post('campaigns/(:num)/status',  'Api\\V1\\CampaignController::setStatus/$1');

        // Landing pages
        $routes->get('landing-pages',           'Api\\V1\\LandingPageController::index');
        $routes->post('landing-pages',          'Api\\V1\\LandingPageController::store');
        $routes->get('landing-pages/(:num)',    'Api\\V1\\LandingPageController::show/$1');
        $routes->put('landing-pages/(:num)',    'Api\\V1\\LandingPageController::update/$1');
        $routes->delete('landing-pages/(:num)', 'Api\\V1\\LandingPageController::destroy/$1');

        // Social planner + queue
        $routes->get('social/posts',                 'Api\\V1\\SocialPostController::index');
        $routes->post('social/posts',                'Api\\V1\\SocialPostController::store');
        $routes->get('social/posts/(:num)',          'Api\\V1\\SocialPostController::show/$1');
        $routes->put('social/posts/(:num)',          'Api\\V1\\SocialPostController::update/$1');
        $routes->delete('social/posts/(:num)',       'Api\\V1\\SocialPostController::destroy/$1');
        $routes->post('social/posts/(:num)/approve', 'Api\\V1\\SocialPostController::approve/$1');
        $routes->post('social/posts/(:num)/reject',  'Api\\V1\\SocialPostController::reject/$1');
        $routes->post('social/posts/(:num)/mark-posted', 'Api\\V1\\SocialPostController::markPosted/$1');
        $routes->get('social/queue', 'Api\\V1\\SocialQueueController::index');

        // Email + WhatsApp campaigns
        $routes->get('email/campaigns',              'Api\\V1\\EmailCampaignController::index');
        $routes->post('email/campaigns',             'Api\\V1\\EmailCampaignController::store');
        $routes->get('email/campaigns/(:num)',       'Api\\V1\\EmailCampaignController::show/$1');
        $routes->put('email/campaigns/(:num)',       'Api\\V1\\EmailCampaignController::update/$1');
        $routes->post('email/campaigns/(:num)/mark-sent', 'Api\\V1\\EmailCampaignController::markSent/$1');

        $routes->get('whatsapp/campaigns',           'Api\\V1\\WhatsAppCampaignController::index');
        $routes->post('whatsapp/campaigns',          'Api\\V1\\WhatsAppCampaignController::store');
        $routes->get('whatsapp/campaigns/(:num)',    'Api\\V1\\WhatsAppCampaignController::show/$1');
        $routes->put('whatsapp/campaigns/(:num)',    'Api\\V1\\WhatsAppCampaignController::update/$1');
        $routes->post('whatsapp/campaigns/(:num)/mark-sent', 'Api\\V1\\WhatsAppCampaignController::markSent/$1');

        // SEO planner + keyword ideas
        $routes->get('seo/plans',              'Api\\V1\\SeoPlanController::index');
        $routes->post('seo/plans',             'Api\\V1\\SeoPlanController::store');
        $routes->get('seo/plans/(:num)',       'Api\\V1\\SeoPlanController::show/$1');
        $routes->put('seo/plans/(:num)',       'Api\\V1\\SeoPlanController::update/$1');
        $routes->delete('seo/plans/(:num)',    'Api\\V1\\SeoPlanController::destroy/$1');

        $routes->get('seo/keywords',           'Api\\V1\\KeywordIdeaController::index');
        $routes->post('seo/keywords',          'Api\\V1\\KeywordIdeaController::store');
        $routes->put('seo/keywords/(:num)',    'Api\\V1\\KeywordIdeaController::update/$1');
        $routes->delete('seo/keywords/(:num)', 'Api\\V1\\KeywordIdeaController::destroy/$1');

        // Creative briefs
        $routes->get('creative-briefs',           'Api\\V1\\CreativeBriefController::index');
        $routes->post('creative-briefs',          'Api\\V1\\CreativeBriefController::store');
        $routes->get('creative-briefs/(:num)',    'Api\\V1\\CreativeBriefController::show/$1');
        $routes->put('creative-briefs/(:num)',    'Api\\V1\\CreativeBriefController::update/$1');
        $routes->delete('creative-briefs/(:num)', 'Api\\V1\\CreativeBriefController::destroy/$1');

        // Analytics (internal metrics + GA4 traffic — ported from Flow)
        $routes->get('analytics/summary',                 'Api\\V1\\AnalyticsController::summary');
        $routes->get('analytics/traffic',                 'Api\\V1\\AnalyticsController::traffic');
        $routes->get('analytics/traffic/overview',        'Api\\V1\\AnalyticsController::trafficOverview');
        $routes->get('analytics/traffic/sources',         'Api\\V1\\AnalyticsController::trafficSources');
        $routes->get('analytics/traffic/leads',           'Api\\V1\\AnalyticsController::trafficLeads');
        $routes->get('analytics/traffic/config-status',   'Api\\V1\\AnalyticsController::trafficConfigStatus');
        $routes->get('analytics/providers',               'Api\\V1\\AnalyticsController::providers');

        // Leads + Engage push
        $routes->get('leads',           'Api\\V1\\LeadController::index');
        $routes->post('leads',          'Api\\V1\\LeadController::store');
        $routes->get('leads/(:num)',    'Api\\V1\\LeadController::show/$1');
        $routes->put('leads/(:num)',    'Api\\V1\\LeadController::update/$1');
        $routes->delete('leads/(:num)', 'Api\\V1\\LeadController::destroy/$1');

        $routes->get('engage-push',              'Api\\V1\\EngagePushController::index');
        $routes->post('engage-push/(:num)',      'Api\\V1\\EngagePushController::push/$1');
        $routes->post('engage-push/(:num)/retry','Api\\V1\\EngagePushController::retry/$1');

        // Marketing Bot
        $routes->get('bot/settings',           'Api\\V1\\BotSettingsController::index');
        $routes->put('bot/settings',           'Api\\V1\\BotSettingsController::update');
        $routes->post('bot/dispatch',          'Api\\V1\\MarketingBotController::dispatch');
        $routes->get('bot/queue',              'Api\\V1\\MarketingBotController::queue');
        $routes->get('bot/queue/(:num)',       'Api\\V1\\MarketingBotController::queueItem/$1');
        $routes->post('bot/queue/(:num)/approve', 'Api\\V1\\MarketingBotController::approveItem/$1');
        $routes->post('bot/queue/(:num)/reject',  'Api\\V1\\MarketingBotController::rejectItem/$1');
        $routes->get('bot/reports',            'Api\\V1\\BotReportController::index');
        $routes->get('bot/reports/(:num)',     'Api\\V1\\BotReportController::show/$1');

        // Approvals — cross-module (blog/campaigns/social/bot)
        $routes->get('approvals',              'Api\\V1\\ApprovalController::index');
        $routes->get('approvals/(:num)',       'Api\\V1\\ApprovalController::show/$1');
        $routes->post('approvals/(:num)/decide', 'Api\\V1\\ApprovalController::decide/$1');

        // Admin: settings, audit logs, health, sync status, worker
        $routes->get('settings',        'Api\\V1\\SettingsController::index');
        $routes->put('settings',        'Api\\V1\\SettingsController::update');

        $routes->get('audit-logs',      'Api\\V1\\AuditLogController::index');

        $routes->get('admin/api-health', 'Api\\V1\\HealthController::detailed');
        $routes->get('admin/console-sync-status', 'Api\\V1\\ConsoleSyncStatusController::index');
        $routes->get('admin/worker-status',       'Api\\V1\\WorkerStatusController::index');
        $routes->post('admin/worker-status/ping', 'Api\\V1\\WorkerStatusController::ping');
    });
});
