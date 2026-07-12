<?php

namespace Config;

use App\Filters\ConsoleTokenFilter;
use App\Filters\CorsFilter;
use App\Filters\JsonBodySizeFilter;
use App\Filters\JwtFilter;
use App\Filters\PermissionFilter;
use App\Filters\PublicCaptureFilter;
use App\Filters\RateLimitFilter;
use App\Filters\RequestIdFilter;
use App\Filters\SuperAdminFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    public array $aliases = [
        'csrf'           => CSRF::class,
        'toolbar'        => DebugToolbar::class,
        'honeypot'       => Honeypot::class,
        'invalidchars'   => InvalidChars::class,
        'secureheaders'  => SecureHeaders::class,
        'forcehttps'     => ForceHTTPS::class,
        'pagecache'      => PageCache::class,
        'performance'    => PerformanceMetrics::class,
        'cors'           => CorsFilter::class,
        'jwt'            => JwtFilter::class,
        'permission'     => PermissionFilter::class,
        'super-admin'    => SuperAdminFilter::class,   // retained for backward-compat; not applied group-wide
        'console-token'  => ConsoleTokenFilter::class,
        'public-capture' => PublicCaptureFilter::class,
        'throttle'       => RateLimitFilter::class,
        'body-size'      => JsonBodySizeFilter::class,
        'request-id'     => RequestIdFilter::class,
    ];

    public array $required = [
        'before' => [
            'forcehttps',
            'pagecache',
        ],
        'after' => [
            'pagecache',
            'performance',
            'toolbar',
        ],
    ];

    public array $globals = [
        'before' => [
            'request-id',
            'cors',
            'body-size',
        ],
        'after' => [
            'request-id',
            'cors',
            'secureheaders',
        ],
    ];

    public array $methods = [];

    public array $filters = [];
}
