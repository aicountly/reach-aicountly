<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inbound guard for the Claude Code routine → Reach `v1/automation/*`
 * endpoints. The token is accepted as `X-Automation-Token: <REACH_AUTOMATION_TOKEN>`
 * or as `Authorization: Bearer <REACH_AUTOMATION_TOKEN>` — the Claude Code
 * environment's network secrets can only inject Basic/Bearer/Digest headers.
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

        $provided = trim((string) $request->getHeaderLine('X-Automation-Token'));
        $via      = $provided !== '' ? 'x-automation-token' : 'none';
        // The environment's network secret always prefixes its value with the
        // scheme it was saved with ("Bearer …"), whatever the header name.
        if (preg_match('/^Bearer\s+(\S+)$/i', $provided, $pm)) {
            $provided = $pm[1];
        }
        $auth     = trim($request->getHeaderLine('Authorization'));
        if ($provided === '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            $provided = $m[1];
            $via      = 'bearer';
        } elseif ($provided === '' && $auth !== '') {
            $via = 'authorization-not-bearer';
        }
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            // Says how a token arrived and its length — never any part of it —
            // so a mismatched secret can be told apart from a missing header.
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'ok'       => false,
                    'error'    => 'Invalid automation token (X-Automation-Token or Authorization: Bearer).',
                    'received' => ['via' => $via, 'length' => strlen($provided), 'expected_length' => strlen($expected)],
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
