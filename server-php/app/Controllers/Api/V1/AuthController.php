<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\RoleModel;
use App\Models\SessionModel;
use App\Models\UserModel;
use Config\Services;
use RuntimeException;
use Throwable;

/**
 * Reach portal auth. Superadmin-only — only users with role_slug='super_admin'
 * may sign in. No OAuth, no self-registration (marketing portal is closed).
 */
class AuthController extends BaseApiController
{
    public function login()
    {
        try {
            $jwtSecret = (string) env('JWT_SECRET', '');
            if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
                return $this->fail(
                    'Server misconfigured: set JWT_SECRET (32+ chars) in api/.env',
                    503,
                );
            }

            $body  = $this->input();
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $pass  = (string) ($body['password'] ?? '');

            if ($email === '' || $pass === '') {
                return $this->fail('email and password required.', 400);
            }

            $users = new UserModel();
            $user  = $users->findByEmail($email);
            if (! $user || ($user['is_active'] ?? true) === false) {
                return $this->fail('Invalid credentials.', 401);
            }

            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash === '' || ! password_verify($pass, $hash)) {
                if ((int) ($user['id'] ?? 0) > 0) {
                    $users->update((int) $user['id'], [
                        'failed_attempts' => ((int) ($user['failed_attempts'] ?? 0)) + 1,
                    ]);
                }
                return $this->fail('Invalid credentials.', 401);
            }

            $role = (new RoleModel())->find((int) ($user['role_id'] ?? 0));
            $roleSlug = (string) ($role['slug'] ?? '');
            if ($roleSlug !== 'super_admin') {
                return $this->fail('Reach is restricted to superadmin users.', 403);
            }

            try {
                $token = Services::jwt()->issue(
                    (int) $user['id'],
                    (string) $user['email'],
                    'super_admin',
                    (string) ($user['name'] ?? ''),
                );
            } catch (RuntimeException $e) {
                return $this->fail($e->getMessage(), 503);
            }

            $sessions = new SessionModel();
            $sessions->createFromToken(
                userId:    (int) $user['id'],
                token:     $token,
                ttlSecs:   Services::jwt()->ttlSeconds(),
                ip:        $this->request->getIPAddress(),
                userAgent: substr((string) $this->request->getUserAgent(), 0, 510),
            );

            $users->update((int) $user['id'], [
                'last_login_at'   => date('Y-m-d H:i:s'),
                'last_login_ip'   => $this->request->getIPAddress(),
                'failed_attempts' => 0,
            ]);

            Services::auditLogger()->log(
                userId:     (int) $user['id'],
                action:     'auth.login',
                entityType: 'user',
                entityId:   (int) $user['id'],
                extra:      ['role' => 'super_admin'],
            );

            return $this->ok([
                'token'      => $token,
                'expires_in' => Services::jwt()->ttlSeconds(),
                'user'       => [
                    'id'    => (int) $user['id'],
                    'email' => (string) $user['email'],
                    'name'  => (string) $user['name'],
                    'role'  => 'super_admin',
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Reach login failed: ' . $e->getMessage());
            return $this->fail('Login failed. Check writable/logs on the server.', 500);
        }
    }

    public function refresh()
    {
        $body    = $this->input();
        $token   = (string) ($body['token'] ?? '');
        $payload = Services::jwt()->decode($token);
        if (! $payload) {
            return $this->fail('Invalid token.', 401);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            return $this->fail('Invalid token subject.', 401);
        }

        $user = (new UserModel())->find($userId);
        if (! $user || ($user['is_active'] ?? true) === false) {
            return $this->fail('User is inactive.', 403);
        }

        $role = (new RoleModel())->find((int) ($user['role_id'] ?? 0));
        if (($role['slug'] ?? '') !== 'super_admin') {
            return $this->fail('Reach is restricted to superadmin users.', 403);
        }

        $newToken = Services::jwt()->issue(
            (int) $user['id'],
            (string) $user['email'],
            'super_admin',
            (string) ($user['name'] ?? ''),
        );
        (new SessionModel())->createFromToken(
            userId:    (int) $user['id'],
            token:     $newToken,
            ttlSecs:   Services::jwt()->ttlSeconds(),
            ip:        $this->request->getIPAddress(),
            userAgent: substr((string) $this->request->getUserAgent(), 0, 510),
        );

        return $this->ok([
            'token'      => $newToken,
            'expires_in' => Services::jwt()->ttlSeconds(),
        ]);
    }

    public function logout()
    {
        $session = $this->request->reachSession ?? null;
        if ($session && is_array($session)) {
            (new SessionModel())->revoke((int) $session['id']);
        }
        Services::auditLogger()->log(
            userId:     $this->userId(),
            action:     'auth.logout',
            entityType: 'user',
            entityId:   $this->userId(),
        );
        return $this->ok(['message' => 'Logged out.']);
    }

    public function me()
    {
        $u = $this->user();
        if (! $u) {
            return $this->fail('Not authenticated.', 401);
        }
        $row = (new UserModel())->find((int) $u['id']);
        if (! $row) {
            return $this->fail('User no longer exists.', 401);
        }
        return $this->ok([
            'id'    => (int) $row['id'],
            'email' => (string) $row['email'],
            'name'  => (string) $row['name'],
            'role'  => 'super_admin',
        ]);
    }
}
