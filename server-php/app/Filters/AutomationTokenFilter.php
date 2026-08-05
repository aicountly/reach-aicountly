<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inbound guard for the Claude Code routine → Reach `v1/automation/*`
 * endpoints. The routine includes `X-Automation-Token: <REACH_AUTOMATION_TOKEN>`.
 * Deliberately NOT the console JWT: routines are headless, long-lived and
 * scoped to exactly these endpoints.
 */
class AutomationTokenFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = (string) env('REACH_AUTOMATION_TOKEN', '');
        if ($expected === '' || strlen($expected) < 32) {
            return service('response')
                ->setStatusCode(503)
                ->setJSON([
                    'ok'    => false,
                    'error' => 'Server misconfigured: REACH_AUTOMATION_TOKEN not set (32+ chars) in api/.env',
                ]);
        }

        $provided = (string) $request->getHeaderLine('X-Automation-Token');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Invalid X-Automation-Token.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
