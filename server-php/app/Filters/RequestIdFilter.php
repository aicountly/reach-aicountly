<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Assign a correlation id to every incoming HTTP request.
 *
 * Behaviour:
 *   - If the client sends `X-Request-Id` matching `[A-Za-z0-9._-]{8,64}`,
 *     it is trusted and re-used. This lets Console/worker/tests thread ids
 *     end-to-end.
 *   - Otherwise a UUIDv4-style id is generated locally. An optional
 *     `REACH_REQUEST_ID_PREFIX` env value is prepended (e.g. `reach-prod:`)
 *     to make greps unambiguous across systems.
 *   - The id is exposed on the request as `$request->reachRequestId` and
 *     echoed back on the response via `X-Request-Id` for the client to
 *     record in their own logs.
 *
 * Downstream consumers:
 *   - AuditLogger auto-picks it up when callers don't pass `requestId`.
 *   - JobService::enqueue() should be called with `request_id` from callers
 *     so the worker can restore it (see MarketingBotService::enqueue).
 *   - AicountlySitePublisher / EngageClient / ConsoleAuditClient forward
 *     the header on outbound HTTP so we can trace cross-service failures.
 */
class RequestIdFilter implements FilterInterface
{
    private const HEADER  = 'X-Request-Id';
    private const PATTERN = '/^[A-Za-z0-9._:-]{8,64}$/';

    public function before(RequestInterface $request, $arguments = null)
    {
        $incoming = trim((string) $request->getHeaderLine(self::HEADER));
        $id = $incoming !== '' && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : $this->generate();

        $request->reachRequestId = $id;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $id = $request->reachRequestId ?? null;
        if (is_string($id) && $id !== '') {
            $response->setHeader(self::HEADER, $id);
        }
    }

    private function generate(): string
    {
        $prefix = $this->resolvePrefix();
        try {
            $bytes = random_bytes(16);
        } catch (\Throwable $e) {
            $bytes = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
        return $prefix !== '' ? $prefix . ':' . $uuid : $uuid;
    }

    /**
     * Resolve the REACH_REQUEST_ID_PREFIX value portably.
     *
     * CI4's env() helper checks $_ENV before $_SERVER and getenv(), using
     * the null-coalescing operator. This means an empty string stored in
     * $_ENV by DotEnv (from .env.example's `REACH_REQUEST_ID_PREFIX=`) will
     * satisfy the ?? operator and prevent the correct value from being read —
     * even if putenv() and $_SERVER have been updated by a caller or test.
     *
     * This resolver checks getenv() first because putenv() updates it
     * synchronously on all supported platforms, making it safe for both
     * production (DotEnv calls putenv() during bootstrap) and test isolation
     * (tests call putenv() to inject a temporary override).
     *
     * Resolution order:
     *   1. getenv(key)          — updated by putenv(); works on all platforms
     *   2. $_ENV[key]           — populated by DotEnv at bootstrap
     *   3. $_SERVER[key]        — populated by DotEnv and web-server SAPI
     *
     * Blank, whitespace-only, false, and null all resolve to no prefix.
     */
    private function resolvePrefix(): string
    {
        $key   = 'REACH_REQUEST_ID_PREFIX';
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        $value = is_string($value) ? trim($value) : '';
        return $value;
    }
}
