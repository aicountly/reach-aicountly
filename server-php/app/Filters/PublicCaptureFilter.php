<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards the /v1/public/leads/capture endpoint. Embeddable landing pages /
 * forms include a rotating token from PUBLIC_LEAD_CAPTURE_TOKEN. Empty env
 * value disables the route (returns 503) so a misconfigured deploy can't
 * accept anonymous writes silently.
 */
class PublicCaptureFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = (string) env('PUBLIC_LEAD_CAPTURE_TOKEN', '');
        if ($expected === '') {
            return service('response')
                ->setStatusCode(503)
                ->setJSON([
                    'ok'    => false,
                    'error' => 'Public lead capture is disabled (PUBLIC_LEAD_CAPTURE_TOKEN not set).',
                ]);
        }

        $provided = (string) $request->getHeaderLine('X-Public-Capture-Token');
        if ($provided === '') {
            // Also accept as query param for image-pixel style captures.
            $provided = (string) ($request->getGet('token') ?? '');
        }
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Invalid capture token.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
