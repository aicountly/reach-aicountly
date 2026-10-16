<?php

namespace App\Services;

use App\Libraries\ConsoleRoleSync;
use App\Models\RoleModel;
use App\Models\SessionModel;
use App\Models\UserModel;
use Config\Services;

/**
 * Mirrors the Console-assigned staff role into reach_users.role_id.
 *
 * Reach is single-role: the highest ranked Console role that exists locally
 * wins (super_admin > reach_admin > marketing_manager > content_reviewer).
 * Per-user grant/deny overrides in reach_user_permissions are portal-local
 * and are left untouched.
 *
 * Login: resolveLoginRole() turns the SSO payload decision into a local
 * role row. Refresh: refresh() re-asks Console's user-access endpoint when
 * CONSOLE_API_URL + CONSOLE_API_TOKEN are set — revoked → refuse and revoke
 * every reach_sessions row; Console unreachable → allowed only while the
 * last confirmed sync is younger than 12h; not configured → warning + the
 * previous behaviour (re-issue with the local role).
 *
 * Results are ['ok' => true, ...] or ['ok' => false, 'status' => int, 'error' => string].
 */
class StaffAccessService
{
    public function __construct(private ?UserModel $users = null, private ?RoleModel $roles = null)
    {
        $this->users ??= new UserModel();
        $this->roles ??= new RoleModel();
    }

    /**
     * Decide which local role a signing-in user gets.
     *
     * @param array<string,mixed>|null $user     existing reach_users row, or null for a new user
     * @param array<string,mixed>      $decision ConsoleRoleSync::fromIdentity() result
     * @return array<string,mixed> ['ok' => true, 'role_id' => int, 'role_slug' => string, 'synced' => bool]
     */
    public function resolveLoginRole(?array $user, array $decision): array
    {
        $status = $decision['status'] ?? '';
        if ($status === 'deny') {
            return ['ok' => false, 'status' => 403, 'error' => (string) $decision['message']];
        }

        if ($status === 'grant') {
            $role = $this->firstLocalRole($decision['roles']);
            if ($role === null) {
                return [
                    'ok'     => false,
                    'status' => 503,
                    'error'  => 'Reach role ' . implode(', ', $decision['roles']) . ' is not configured. Run php spark reach:migrate.',
                ];
            }

            return ['ok' => true, 'role_id' => (int) $role['id'], 'role_slug' => (string) $role['slug'], 'synced' => true];
        }

        // Legacy Console payload (no app_roles): keep the local role.
        log_message(
            'warning',
            'Console SSO payload has no app_roles; keeping the local Reach role. '
            . 'Upgrade Console so roles come from Console → Roles & Access.',
        );
        if ($user !== null) {
            $role = $this->roles->find((int) ($user['role_id'] ?? 0));
            if ($role) {
                return ['ok' => true, 'role_id' => (int) $role['id'], 'role_slug' => (string) $role['slug'], 'synced' => false];
            }
        }
        if ((bool) ($decision['superadmin'] ?? false)) {
            $role = $this->roles->findBySlug(ConsoleRoleSync::SUPER_ADMIN);
            if ($role) {
                return ['ok' => true, 'role_id' => (int) $role['id'], 'role_slug' => (string) $role['slug'], 'synced' => false];
            }

            return ['ok' => false, 'status' => 503, 'error' => 'Reach super_admin role is not configured.'];
        }

        return ['ok' => false, 'status' => 403, 'error' => ConsoleRoleSync::DENY_MESSAGE];
    }

    /**
     * Columns to write on reach_users after a successful login decision.
     *
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $resolved resolveLoginRole() result
     * @return array<string,mixed>
     */
    public function syncColumns(array $decision, array $resolved): array
    {
        $row = ['role_id' => (int) $resolved['role_id']];
        if (($decision['console_user_id'] ?? null) !== null) {
            $row['console_user_id'] = (int) $decision['console_user_id'];
        }
        if (! empty($resolved['synced'])) {
            $row['roles_synced_at'] = self::nowTz();
            if (($decision['authz_version'] ?? null) !== null) {
                $row['authz_version'] = (int) $decision['authz_version'];
            }
        }

        return $row;
    }

    /**
     * Re-check access with Console before minting a refreshed token.
     *
     * @param array<string,mixed> $user reach_users row
     * @return array<string,mixed> ['ok' => true, 'role_slug' => string]
     */
    public function refresh(array $user, ?int $now = null): array
    {
        $userId = (int) $user['id'];
        if (! $this->isActive($user)) {
            return ['ok' => false, 'status' => 403, 'error' => 'User is inactive.'];
        }

        $console = Services::consoleIdentity();
        if (! $console->userAccessConfigured()) {
            log_message(
                'warning',
                'CONSOLE_API_URL / CONSOLE_API_TOKEN not set: Reach token refresh cannot re-check roles with Console. '
                . 'Production must set both.',
            );

            return $this->currentRole($user);
        }

        $consoleUserId = ($user['console_user_id'] ?? null) !== null ? (int) $user['console_user_id'] : null;
        $result        = $console->fetchUserAccess($consoleUserId, (string) ($user['email'] ?? ''));

        if (($result['status'] ?? '') !== 'ok') {
            $syncedAt = isset($user['roles_synced_at']) ? (string) $user['roles_synced_at'] : null;
            if (ConsoleRoleSync::withinGrace($syncedAt, $now)) {
                log_message('warning', 'Console user-access unreachable; using Reach role synced at ' . $syncedAt . ' for user ' . $userId);

                return $this->currentRole($user);
            }

            return ['ok' => false, 'status' => 503, 'error' => ConsoleRoleSync::CONSOLE_DOWN_MESSAGE];
        }

        $decision = ConsoleRoleSync::fromUserAccess((array) $result['data']);
        if ($decision['status'] === 'deny') {
            $this->revokeSessions($userId);

            return ['ok' => false, 'status' => 403, 'error' => (string) $decision['message']];
        }

        $role = $this->firstLocalRole($decision['roles']);
        if ($role === null) {
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Reach role ' . implode(', ', $decision['roles']) . ' is not configured. Run php spark reach:migrate.',
            ];
        }

        $row = ['role_id' => (int) $role['id'], 'roles_synced_at' => self::nowTz()];
        if ($decision['console_user_id'] !== null) {
            $row['console_user_id'] = (int) $decision['console_user_id'];
        }
        if ($decision['authz_version'] !== null) {
            $row['authz_version'] = (int) $decision['authz_version'];
        }
        $this->users->update($userId, $row);
        Services::permissionService()->invalidate($userId);

        return ['ok' => true, 'role_slug' => (string) $role['slug']];
    }

    /** Revoke every live reach_sessions row of this user. */
    public function revokeSessions(int $userId): void
    {
        (new SessionModel())->builder()
            ->where('user_id', $userId)
            ->where('revoked_at', null)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @param array<string,mixed> $user */
    private function currentRole(array $user): array
    {
        $role = $this->roles->find((int) ($user['role_id'] ?? 0));
        if (! $role) {
            return ['ok' => false, 'status' => 403, 'error' => ConsoleRoleSync::DENY_MESSAGE];
        }

        return ['ok' => true, 'role_slug' => (string) $role['slug']];
    }

    /**
     * First role (in rank order) that exists in reach_roles.
     *
     * @param list<string> $ranked
     * @return array<string,mixed>|null
     */
    private function firstLocalRole(array $ranked): ?array
    {
        foreach ($ranked as $slug) {
            $role = $this->roles->findBySlug($slug);
            if ($role) {
                return $role;
            }
            log_message('error', "Reach role '{$slug}' assigned in Console is missing from reach_roles.");
        }

        return null;
    }

    /** @param array<string,mixed> $user */
    private function isActive(array $user): bool
    {
        $active = $user['is_active'] ?? true;
        if (is_string($active)) {
            $active = in_array(strtolower($active), ['t', 'true', '1'], true);
        }

        return (bool) $active && ! self::truthy($user['is_login_disabled'] ?? false);
    }

    private static function truthy(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower($v), ['t', 'true', '1'], true);
        }

        return (bool) $v;
    }

    /** TIMESTAMPTZ literal in UTC, independent of the PHP / DB session time zones. */
    private static function nowTz(): string
    {
        return gmdate('Y-m-d H:i:s') . '+00';
    }
}
