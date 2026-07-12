<?php

namespace Config;

/**
 * Per-route rate-limit policies used by App\Filters\RateLimitFilter.
 *
 * The filter is invoked as `throttle:<policy>` in routes. Each policy
 * specifies:
 *   limit         — maximum allowed hits per window
 *   window_secs   — length of the sliding fixed window in seconds
 *   scope         — 'ip' | 'user' | 'ip+user'
 *   audit_after   — emit `security.rate_limited` after this many consecutive
 *                   blocks within a window (0 disables the audit)
 */
final class RateLimits
{
    /** @return array<string, array{limit:int, window_secs:int, scope:string, audit_after:int}> */
    public static function policies(): array
    {
        return [
            'auth' => [
                'limit'       => 10,
                'window_secs' => 60,
                'scope'       => 'ip',
                'audit_after' => 3,
            ],
            'public_capture' => [
                // Per-IP layer.
                'limit'       => 5,
                'window_secs' => 60,
                'scope'       => 'ip',
                'audit_after' => 3,
            ],
            'public_capture_token' => [
                // Per capture-token layer (hits the token issued to a form).
                'limit'       => 100,
                'window_secs' => 3600,
                'scope'       => 'token',
                'audit_after' => 5,
            ],
            'bot_dispatch' => [
                'limit'       => 30,
                'window_secs' => 60,
                'scope'       => 'user',
                'audit_after' => 3,
            ],
            'approval' => [
                'limit'       => 60,
                'window_secs' => 60,
                'scope'       => 'user',
                'audit_after' => 5,
            ],
            'integration' => [
                'limit'       => 30,
                'window_secs' => 60,
                'scope'       => 'user',
                'audit_after' => 3,
            ],
        ];
    }
}
