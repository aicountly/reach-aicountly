<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\ConsoleRoleSync;
use App\Models\SessionModel;
use App\Models\UserModel;
use App\Services\ConsoleIdentityService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Throwable;

/**
 * Reach portal auth. Console SSO is the sole sign-in path (no local
 * credentials). The staff role comes from Console → Roles & Access
 * (super_admin, reach_admin, marketing_manager, content_reviewer) and is
 * mirrored into reach_users.role_id; every authenticated route is then gated
 * by its permission:* filter. See StaffAccessService.
 */
class AuthController extends BaseApiController
{
    private const REACH_TOKEN_STORAGE_KEY = 'reach_token';

    public function login()
    {
        return $this->fail(
            'Local login is disabled. Sign in at console.aicountly.org and open Reach from Top Controller Apps.',
            403,
        );
    }

    /**
     * POST /v1/auth/refresh {token} — re-issue a token after re-checking the
     * caller's role with Console (see StaffAccessService::refresh).
     */
    public function refresh()
    {
        $body  = $this->input();
        $token = (string) ($body['token'] ?? '');
        try {
            $payload = Services::jwt()->decode($token);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 503);
        }
        if (! $payload) {
            return $this->fail('Invalid token.', 401);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            return $this->fail('Invalid token subject.', 401);
        }

        // A token whose session was revoked (logout, or Console revoking
        // access) cannot be refreshed back to life.
        if (! (new SessionModel())->findActiveByTokenHash(hash('sha256', $token))) {
            return $this->fail('Session is no longer active. Please sign in again.', 401);
        }

        $user = (new UserModel())->find($userId);
        if (! $user) {
            return $this->fail('User is inactive.', 403);
        }

        $result = Services::staffAccess()->refresh($user);
        if (! $result['ok']) {
            if ((int) $result['status'] === 403) {
                Services::auditLogger()->log(
                    userId:     $userId,
                    action:     'auth.console_access_revoked',
                    entityType: 'user',
                    entityId:   $userId,
                    extra:      ['reason' => $result['error']],
                );
            }

            return $this->fail((string) $result['error'], (int) $result['status']);
        }

        $roleSlug = (string) $result['role_slug'];
        $newToken = Services::jwt()->issue(
            (int) $user['id'],
            (string) $user['email'],
            $roleSlug,
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
            'token'       => $newToken,
            'expires_in'  => Services::jwt()->ttlSeconds(),
            'role'        => $roleSlug,
            'permissions' => Services::permissionService()->resolveEffective((int) $user['id']),
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
        $row = (new UserModel())->withRole((int) $u['id']);
        if (! $row) {
            return $this->fail('User no longer exists.', 401);
        }
        $roleSlug = (string) ($row['role_slug'] ?? '');
        $permissions = Services::permissionService()->resolveEffective((int) $row['id']);

        return $this->ok([
            'id'              => (int) $row['id'],
            'email'           => (string) $row['email'],
            'name'            => (string) $row['name'],
            'role'            => $roleSlug,
            'role_slug'       => $roleSlug,
            'role_name'       => (string) ($row['role_name'] ?? ''),
            'permissions'     => $permissions,
            'actor_type'      => (string) ($row['actor_type'] ?? 'human'),
            'roles_synced_at' => $row['roles_synced_at'] ?? null,
            'roles_managed_by'=> 'console',
            'controller_apps' => $this->controllerAppsForRequest(),
            'ui_timezone'     => \App\Libraries\Support\DisplayTime::zoneName(),
        ]);
    }

    public function controllerAppsLauncher()
    {
        $u = $this->user();
        if (! $u) {
            return $this->fail('Not authenticated.', 401);
        }

        $apps = $this->controllerAppsForRequest();
        if ($apps === []) {
            return $this->fail(
                'Console session required for Top Controller Apps. Sign in at console.aicountly.org first.',
                401,
            );
        }

        return $this->ok(['apps' => $apps]);
    }

    public function ssoLaunchUrl()
    {
        $u = $this->user();
        if (! $u) {
            return $this->fail('Not authenticated.', 401);
        }

        $appCode = strtolower(trim((string) ($this->request->getGet('app_code') ?? '')));
        if ($appCode === '') {
            return $this->fail('app_code query parameter is required.', 422);
        }

        $consoleToken = $this->consoleTokenFromRequest();
        if ($consoleToken === '') {
            return $this->fail('Console session required to launch controller apps.', 401);
        }

        $data = Services::consoleIdentity()->getSsoLaunchUrl($consoleToken, $appCode);
        $redirectUrl = trim((string) ($data['redirect_url'] ?? ''));
        if ($redirectUrl === '') {
            return $this->fail('Console did not return a launch URL for this app.', 502);
        }

        return $this->ok(['redirect_url' => $redirectUrl]);
    }

    /**
     * GET /v1/auth/sso-callback?token= — browser redirect from Console (no SPA JS required).
     */
    public function ssoCallback()
    {
        try {
            if ($fail = $this->ensureJwtConfigured()) {
                return $this->ssoCallbackHtml('Reach Portal is not configured for Console SSO yet.', 503);
            }

            $token = trim((string) ($this->request->getGet('token') ?? $this->request->getGet('sesskey') ?? ''));
            if ($token === '') {
                return $this->ssoCallbackHtml('Missing SSO token. Open Reach again from Console Top Controller Apps.', 400);
            }

            $identity = Services::consoleIdentity()->exchangeLaunchToken($token);
            if ($identity === null) {
                return $this->ssoCallbackHtml(
                    'This sign-in link expired. Go back to Console and click Reach again.',
                    401,
                );
            }

            $session = $this->buildSessionFromConsoleIdentity($identity, 'auth.controller_sso_callback');
            if ($session instanceof ResponseInterface) {
                $message = 'You do not have access to the Reach controller app.';
                $json    = json_decode((string) $session->getBody(), true);
                if (is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '') {
                    $message = $json['error'];
                }

                return $this->ssoCallbackHtml($message, $session->getStatusCode() >= 400 ? $session->getStatusCode() : 403);
            }

            return $this->completeSsoInBrowser((string) $session['token']);
        } catch (Throwable $e) {
            log_message('error', 'SSO callback failed: ' . $e->getMessage());

            return $this->ssoCallbackHtml('Console SSO sign-in failed. Try again from Console.', 500);
        }
    }

    /**
     * Exchange a Console controller SSO launch token for a Reach session.
     */
    public function controllerSso()
    {
        try {
            if ($fail = $this->ensureJwtConfigured()) {
                return $fail;
            }

            $body  = $this->input();
            $token = trim((string) ($body['token'] ?? ''));
            if ($token === '') {
                return $this->fail('token required.', 400);
            }

            $identity = Services::consoleIdentity()->exchangeLaunchToken($token);
            if ($identity === null) {
                return $this->fail('Invalid or expired Console SSO token.', 401);
            }

            $session = $this->buildSessionFromConsoleIdentity($identity, 'auth.controller_sso_login');
            if ($session instanceof ResponseInterface) {
                return $session;
            }

            return $this->ok($session);
        } catch (Throwable $e) {
            log_message('error', 'Controller SSO failed: ' . $e->getMessage());

            return $this->fail('Controller SSO login failed.', 500);
        }
    }

    /**
     * Sign in using the shared Console cookie (direct visit to reach.aicountly.org).
     */
    public function consoleSession()
    {
        try {
            if ($fail = $this->ensureJwtConfigured()) {
                return $fail;
            }

            $consoleToken = trim((string) ($this->request->getCookie(ConsoleIdentityService::cookieName()) ?? ''));
            if ($consoleToken === '') {
                return $this->fail('Sign in to Console first.', 401);
            }

            $identity = Services::consoleIdentity()->introspectSession($consoleToken);
            if ($identity === null) {
                return $this->fail('Console session is invalid or expired. Sign in again at Console.', 401);
            }

            $session = $this->buildSessionFromConsoleIdentity($identity, 'auth.console_session_login');
            if ($session instanceof ResponseInterface) {
                return $session;
            }

            return $this->ok($session);
        } catch (Throwable $e) {
            log_message('error', 'Console session login failed: ' . $e->getMessage());

            return $this->fail('Console session login failed.', 500);
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>|ResponseInterface
     */
    private function buildSessionFromConsoleIdentity(array $identity, string $auditEvent): array|ResponseInterface
    {
        $active = (bool) ($identity['active'] ?? false);
        if (! $active) {
            return $this->fail('You do not have access to the Reach controller app.', 403);
        }

        $global = (bool) ($identity['global_superadmin'] ?? false);

        $consoleUser = is_array($identity['user'] ?? null) ? $identity['user'] : [];
        $email = strtolower(trim((string) ($consoleUser['email'] ?? '')));
        $name  = trim((string) ($consoleUser['name'] ?? ''));
        if ($email === '') {
            return $this->fail('Console identity did not return a user email.', 502);
        }

        // The role comes from Console. Decide before provisioning anything so
        // a staff member without a Reach role never gets a local row.
        $decision = ConsoleRoleSync::fromIdentity($identity);
        if ($decision['status'] === 'deny') {
            return $this->fail((string) $decision['message'], 403);
        }

        $users = new UserModel();
        $user  = $users->findByEmail($email);

        if ($user && (! $this->truthy($user['is_active'] ?? true) || $this->truthy($user['is_login_disabled'] ?? false))) {
            return $this->fail('Reach user account is inactive.', 403);
        }

        $access   = Services::staffAccess();
        $resolved = $access->resolveLoginRole($user, $decision);
        if (! $resolved['ok']) {
            return $this->fail((string) $resolved['error'], (int) $resolved['status']);
        }
        $sync     = $access->syncColumns($decision, $resolved);
        $roleSlug = (string) $resolved['role_slug'];

        if (! $user) {
            $userId = $users->insert(array_merge([
                'email'         => $email,
                'name'          => $name !== '' ? $name : $email,
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'is_active'     => true,
            ], $sync));

            if (! $userId) {
                return $this->fail('Could not provision Reach user from Console identity.', 500);
            }

            $user = $users->find((int) $userId);
        } else {
            $users->update((int) $user['id'], $sync);
            $user = $users->find((int) $user['id']);
        }
        Services::permissionService()->invalidate((int) $user['id']);

        try {
            $reachToken = Services::jwt()->issue(
                (int) $user['id'],
                (string) $user['email'],
                $roleSlug,
                (string) ($user['name'] ?? ''),
            );
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 503);
        }

        (new SessionModel())->createFromToken(
            userId:    (int) $user['id'],
            token:     $reachToken,
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
            action:     $auditEvent,
            entityType: 'user',
            entityId:   (int) $user['id'],
            extra:      [
                'role'              => $roleSlug,
                'roles_source'      => $decision['status'] === 'grant' ? 'console' : 'local_legacy',
                'console_user_id'   => (int) ($decision['console_user_id'] ?? ($consoleUser['id'] ?? 0)),
                'authz_version'     => $decision['authz_version'] ?? null,
                'global_superadmin' => $global,
            ],
        );

        $permissions = Services::permissionService()->resolveEffective((int) $user['id']);

        return [
            'token'      => $reachToken,
            'expires_in' => Services::jwt()->ttlSeconds(),
            'user'       => [
                'id'              => (int) $user['id'],
                'email'           => (string) $user['email'],
                'name'            => (string) $user['name'],
                'role'            => $roleSlug,
                'role_slug'       => $roleSlug,
                'permissions'     => $permissions,
                'actor_type'      => 'human',
                'ui_timezone'     => \App\Libraries\Support\DisplayTime::zoneName(),
                'controller_apps' => $this->normalizeLauncherApps(
                    is_array($identity['controller_apps'] ?? null) ? $identity['controller_apps'] : [],
                ),
            ],
        ];
    }

    private function controllerAppsForRequest(): array
    {
        $consoleToken = $this->consoleTokenFromRequest();
        if ($consoleToken === '') {
            return [];
        }

        $data = Services::consoleIdentity()->getLauncherApps($consoleToken);
        if (! is_array($data)) {
            return [];
        }

        return $this->normalizeLauncherApps(
            is_array($data['apps'] ?? null) ? $data['apps'] : [],
        );
    }

    private function consoleTokenFromRequest(): string
    {
        return trim((string) ($this->request->getCookie(ConsoleIdentityService::cookieName()) ?? ''));
    }

    private function normalizeLauncherApps(array $apps): array
    {
        $current = strtolower(trim((string) env('CONTROLLER_APP_CODE', 'reach')));

        return array_values(array_map(static function (array $app) use ($current): array {
            $code = strtolower(trim((string) ($app['code'] ?? '')));
            $app['is_current'] = $code === $current;

            return $app;
        }, $apps));
    }

    private function completeSsoInBrowser(string $reachToken): ResponseInterface
    {
        $tokenJson = json_encode($reachToken, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $storageKey = json_encode(self::REACH_TOKEN_STORAGE_KEY, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Signing in to Reach Portal…</title>
  <style>
    body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; color: #334155; }
  </style>
</head>
<body>
  <p>Signing you in to Reach Portal…</p>
  <script>
    try {
      localStorage.setItem({$storageKey}, {$tokenJson});
    } catch (e) {}
    location.replace('/');
  </script>
</body>
</html>
HTML;

        return $this->response
            ->setStatusCode(200)
            ->setContentType('text/html')
            ->setBody($html);
    }

    private function ssoCallbackHtml(string $message, int $status = 400): ResponseInterface
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $consoleUrl  = 'https://console.aicountly.org';
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reach sign-in failed</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 420px; margin: 48px auto; padding: 0 16px; color: #334155; }
    .box { border: 1px solid #fecaca; background: #fef2f2; border-radius: 12px; padding: 16px; }
    a { color: #047857; }
  </style>
</head>
<body>
  <div class="box">
    <h1 style="font-size:18px;margin:0 0 8px;">Reach sign-in failed</h1>
    <p style="margin:0 0 12px;">{$safeMessage}</p>
    <p style="margin:0;"><a href="{$consoleUrl}">Return to Console</a></p>
  </div>
</body>
</html>
HTML;

        return $this->response
            ->setStatusCode($status)
            ->setContentType('text/html')
            ->setBody($html);
    }

    private function ensureJwtConfigured(): ?ResponseInterface
    {
        $jwtSecret = (string) env('JWT_SECRET', '');
        if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
            return $this->fail(
                'Server misconfigured: set JWT_SECRET (32+ chars) in api/.env',
                503
            );
        }

        return null;
    }

    /** Postgres booleans may arrive as 't' / 'f' strings. */
    private function truthy(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower($v), ['t', 'true', '1'], true);
        }

        return (bool) $v;
    }
}
