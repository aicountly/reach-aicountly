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
        $prefix = trim((string) env('REACH_REQUEST_ID_PREFIX', ''));
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
}
