<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Reach is a superadmin-only marketing portal. The JwtFilter has already
 * loaded the user; this filter simply enforces role = super_admin.
 */
class SuperAdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = $request->reachUser ?? null;
        if (! $user) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Not authenticated.']);
        }

        if (($user['role'] ?? '') !== 'super_admin') {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'ok'    => false,
                    'error' => 'Reach is restricted to superadmin users.',
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
