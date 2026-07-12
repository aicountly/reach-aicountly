<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\RateLimits;
use Config\Services;

/**
 * Postgres-backed fixed-window rate limiter.
 *
 * Usage: attach as a route/group filter with a policy alias:
 *   ['filter' => 'throttle:auth']
 *   ['filter' => 'throttle:bot_dispatch']
 *
 * Response on block: HTTP 429 with body
 *   { "ok": false, "error": "Too many requests", "retry_after": <seconds> }
 * and a `Retry-After: <seconds>` header.
 *
 * Trusted proxy handling: when `TRUSTED_PROXIES` env is set to a comma-separated
 * IP list AND the immediate peer matches one of them, the leftmost value of
 * `X-Forwarded-For` is used as the client IP. Otherwise the peer IP is used.
 */
class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $policyName = is_array($arguments) ? (string) ($arguments[0] ?? '') : '';
        if ($policyName === '') {
            return;
        }
        $policies = RateLimits::policies();
        if (! isset($policies[$policyName])) {
            log_message('warning', "RateLimitFilter: unknown policy '{$policyName}'.");
            return;
        }
        $policy = $policies[$policyName];

        $ident = $this->identifierForScope($request, (string) $policy['scope']);
        if ($ident === null) {
            // No identity available for this scope (e.g. user scope without JWT) — allow.
            return;
        }
        $bucketKey = "reach:{$policyName}:{$ident}";
        $windowStart = $this->windowStart((int) $policy['window_secs']);

        [$count, $blockedHits] = $this->incrementBucket($bucketKey, $windowStart);

        $limit = (int) $policy['limit'];
        $retryAfter = $this->secondsUntilWindowEnd($windowStart, (int) $policy['window_secs']);

        if ($count > $limit) {
            $this->markBlocked($bucketKey, $windowStart);
            $newBlocked = $blockedHits + 1;
            $auditAfter = (int) ($policy['audit_after'] ?? 0);
            if ($auditAfter > 0 && $newBlocked === $auditAfter) {
                $this->auditRateLimited($request, $policyName, $ident, $count);
            }

            return service('response')
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $retryAfter)
                ->setJSON([
                    'ok'          => false,
                    'error'       => 'Too many requests',
                    'retry_after' => $retryAfter,
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function identifierForScope(RequestInterface $request, string $scope): ?string
    {
        return match ($scope) {
            'ip'      => $this->clientIp($request),
            'user'    => isset($request->reachUser['id']) ? 'u' . (int) $request->reachUser['id'] : null,
            'ip+user' => (isset($request->reachUser['id']) ? 'u' . (int) $request->reachUser['id'] : 'anon')
                          . ':' . $this->clientIp($request),
            'token'   => (string) ($request->getHeaderLine('X-Reach-Capture-Token') ?: 'notoken'),
            default   => $this->clientIp($request),
        };
    }

    private function clientIp(RequestInterface $request): string
    {
        $peer = $request->getIPAddress();
        $trusted = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trusted === '') {
            return $peer;
        }
        $trustedList = array_filter(array_map('trim', explode(',', $trusted)));
        if (! in_array($peer, $trustedList, true)) {
            return $peer;
        }
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff === '') {
            return $peer;
        }
        $first = trim(explode(',', $xff)[0] ?? '');
        return $first !== '' ? $first : $peer;
    }

    private function windowStart(int $windowSecs): string
    {
        $now = time();
        $anchored = $now - ($now % max(1, $windowSecs));
        return gmdate('Y-m-d H:i:s', $anchored);
    }

    private function secondsUntilWindowEnd(string $windowStart, int $windowSecs): int
    {
        return max(1, ($windowSecs) - (time() - strtotime($windowStart . ' UTC')));
    }

    /**
     * Atomic upsert + increment via ON CONFLICT.
     * @return array{0:int,1:int} [count, blocked_hits_before_increment]
     */
    private function incrementBucket(string $key, string $windowStart): array
    {
        $db = Database::connect();
        $sql = 'INSERT INTO reach_rate_limits (bucket_key, window_start, tokens, blocked_hits, updated_at)
                VALUES (?, ?, 1, 0, NOW())
                ON CONFLICT (bucket_key, window_start)
                DO UPDATE SET tokens = reach_rate_limits.tokens + 1, updated_at = NOW()
                RETURNING tokens, blocked_hits';
        $q = $db->query($sql, [$key, $windowStart]);
        $row = $q ? $q->getRowArray() : null;
        return [(int) ($row['tokens'] ?? 1), (int) ($row['blocked_hits'] ?? 0)];
    }

    private function markBlocked(string $key, string $windowStart): void
    {
        $db = Database::connect();
        $db->query(
            'UPDATE reach_rate_limits SET blocked_hits = blocked_hits + 1, updated_at = NOW()
             WHERE bucket_key = ? AND window_start = ?',
            [$key, $windowStart],
        );
    }

    private function auditRateLimited(RequestInterface $request, string $policy, string $ident, int $count): void
    {
        $userId = isset($request->reachUser['id']) ? (int) $request->reachUser['id'] : 0;
        try {
            Services::auditLogger()->log(
                userId: $userId,
                action: 'security.rate_limited',
                entityType: 'route',
                entityId: null,
                newValue: [
                    'policy'  => $policy,
                    'ident'   => $ident,
                    'count'   => $count,
                    'path'    => (string) $request->getUri()->getPath(),
                    'method'  => (string) $request->getMethod(),
                ],
                actorType: isset($request->reachUser['actor_type']) ? (string) $request->reachUser['actor_type'] : 'human',
                actorService: 'reach:api',
                requestId: $request->reachRequestId ?? null,
            );
        } catch (\Throwable $e) {
            log_message('warning', 'RateLimitFilter: audit write failed — ' . $e->getMessage());
        }
    }
}
