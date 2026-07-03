<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inbound guard for Console → Reach `portal/*` endpoints.
 *
 * Console must include `X-Console-Token: <CONSOLE_INBOUND_TOKEN>`.
 * These endpoints are not JWT-authenticated — they are portal-to-portal
 * calls signed by a shared secret configured in Reach's api/.env.
 */
class ConsoleTokenFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = (string) env('CONSOLE_INBOUND_TOKEN', '');
        if ($expected === '') {
            return service('response')
                ->setStatusCode(503)
                ->setJSON([
                    'ok'    => false,
                    'error' => 'Server misconfigured: CONSOLE_INBOUND_TOKEN not set in api/.env',
                ]);
        }

        $provided = (string) $request->getHeaderLine('X-Console-Token');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Invalid X-Console-Token.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
