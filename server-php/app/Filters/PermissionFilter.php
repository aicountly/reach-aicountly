<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Enforces `permission:<slug>` (or multiple permissions, any of which grants
 * access) using the PermissionService. Must run AFTER JwtFilter so
 * $request->reachUser is populated. Denials return 403 and are audit-logged
 * as `permission.denied`.
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = $request->reachUser ?? null;
        if (! $user) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Not authenticated.']);
        }

        $required = is_array($arguments) ? array_values(array_filter($arguments)) : [];
        if (empty($required)) {
            // No permission argument: treat as authenticated-only.
            return;
        }

        $svc = Services::permissionService();
        foreach ($required as $perm) {
            if ($svc->hasPermission((int) $user['id'], (string) $perm)) {
                return;
            }
        }

        Services::auditLogger()->log(
            userId: (int) $user['id'],
            action: 'permission.denied',
            entityType: 'route',
            entityId: null,
            oldValue: null,
            newValue: [
                'required' => $required,
                'path'     => (string) $request->getUri()->getPath(),
                'method'   => (string) $request->getMethod(),
            ],
            actorType: $user['actor_type'] ?? 'human',
            actorService: 'reach:api',
            requestId: $request->reachRequestId ?? null,
        );

        return service('response')
            ->setStatusCode(403)
            ->setJSON([
                'ok'    => false,
                'error' => 'You do not have permission to perform this action.',
                'details' => ['required' => $required],
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
