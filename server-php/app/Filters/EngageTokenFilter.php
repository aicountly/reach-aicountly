<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inbound guard for Engage → Reach `inbound/engage/*` endpoints.
 *
 * Engage sends `X-Engage-Token: <REACH_INBOUND_ENGAGE_TOKEN>`. Server-to-server,
 * no JWT. Fails closed: an unset token — or one shorter than 32 characters,
 * which is treated as unset — answers 503; a missing or wrong header 401.
 * Compared with hash_equals.
 */
class EngageTokenFilter implements FilterInterface
{
    public const MIN_TOKEN_LENGTH = 32;

    public static function configuredToken(): ?string
    {
        $token = trim((string) env('REACH_INBOUND_ENGAGE_TOKEN', ''));

        return strlen($token) >= self::MIN_TOKEN_LENGTH ? $token : null;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = self::configuredToken();
        if ($expected === null) {
            return service('response')
                ->setStatusCode(503)
                ->setJSON([
                    'ok'    => false,
                    'error' => 'Engage inbound is not configured (REACH_INBOUND_ENGAGE_TOKEN unset or shorter than 32 characters).',
                ]);
        }

        $provided = (string) $request->getHeaderLine('X-Engage-Token');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Invalid X-Engage-Token.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
