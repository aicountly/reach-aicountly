<?php

namespace App\Filters;

use App\Models\SessionModel;
use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Bearer JWT validation. Populates $request->reachUser with the current
 * superadmin's identity. Also checks the sessions table so revoked/expired
 * sessions are rejected without waiting for JWT expiry.
 */
class JwtFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Missing or malformed Authorization header.']);
        }

        try {
            $payload = Services::jwt()->decode($m[1]);
        } catch (\Throwable $e) {
            return service('response')
                ->setStatusCode(503)
                ->setJSON(['ok' => false, 'error' => 'Server misconfigured: JWT_SECRET in api/.env']);
        }

        if (! $payload) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Invalid or expired token.']);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Invalid token subject.']);
        }

        // Live session lookup — revoked sessions cannot use their JWT.
        $tokenHash = hash('sha256', $m[1]);
        $session   = (new SessionModel())->findActiveByTokenHash($tokenHash);
        if (! $session) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Session is no longer active. Please sign in again.']);
        }

        // The role comes from the database, not the token: roles are assigned
        // in Console and re-synced on sign-in and refresh, so a demoted or
        // deactivated user must lose super_admin-only routes immediately rather
        // than when their token expires.
        $row = (new UserModel())->withRole($userId);
        if (! $row || ! self::truthy($row['is_active'] ?? false) || self::truthy($row['is_login_disabled'] ?? false)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Session is no longer active. Please sign in again.']);
        }

        $request->reachUser = [
            'id'    => $userId,
            'email' => (string) ($row['email'] ?? $payload['email'] ?? ''),
            'name'  => (string) ($row['name'] ?? $payload['name'] ?? ''),
            'role'  => (string) ($row['role_slug'] ?? ''),
        ];
        $request->reachSession = $session;
    }

    /** PostgreSQL booleans come back as 't'/'f' strings through the CI4 driver. */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
