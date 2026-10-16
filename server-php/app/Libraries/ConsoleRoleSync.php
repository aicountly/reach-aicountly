<?php

namespace App\Libraries;

/**
 * Pure decision logic for Console-managed Reach staff roles (no DB, no HTTP).
 *
 * Console (console.aicountly.org → Roles & Access) is the source of truth for
 * who may use Reach and with which role. Reach is single-role
 * (reach_users.role_id), so when Console sends several roles the highest
 * ranked one wins:
 *
 *     super_admin > reach_admin > marketing_manager > content_reviewer
 *
 * Per-user grant/deny overrides (reach_user_permissions) are portal-local and
 * are never touched by a sync.
 *
 * Inputs: the SSO exchange / introspect payload (fromIdentity) and the
 * server-to-server user-access pull (fromUserAccess). Each returns:
 *   ['status' => 'grant',  'roles' => list<string> (ranked), 'superadmin' => bool,
 *    'console_user_id' => ?int, 'authz_version' => ?int]
 *   ['status' => 'deny',   'message' => string, 'revoked' => bool]
 *   ['status' => 'legacy', 'superadmin' => bool, 'console_user_id' => ?int]
 *       — Console predates role sync (no app_roles key): existing users keep
 *         their local role; a user without one is refused unless they are a
 *         Console global superadmin.
 */
final class ConsoleRoleSync
{
    /** Reach role catalog (Console app_code `reach`), highest rank first. */
    public const RANKED = ['super_admin', 'reach_admin', 'marketing_manager', 'content_reviewer'];

    public const SUPER_ADMIN = 'super_admin';

    public const DENY_MESSAGE    = 'Access not assigned. Ask a Console superadmin to assign you a role.';
    public const REVOKED_MESSAGE = 'Your Reach access was removed in Console. Ask a Console superadmin to assign you a role.';
    public const CONSOLE_DOWN_MESSAGE = 'Could not confirm your Reach access with Console, and your last confirmed role sync is older than 12 hours. Try again shortly.';

    /** How long locally mirrored roles stay trustworthy when Console cannot be reached. */
    public const GRACE_SECONDS = 12 * 3600;

    /**
     * @param array<string,mixed> $identity SSO exchange / introspect `data`
     * @return array<string,mixed>
     */
    public static function fromIdentity(array $identity): array
    {
        $consoleUserId = self::consoleUserId($identity);
        $global        = (bool) ($identity['global_superadmin'] ?? false);

        if (! array_key_exists('app_roles', $identity)) {
            return [
                'status'          => 'legacy',
                'superadmin'      => $global,
                'console_user_id' => $consoleUserId,
            ];
        }

        $appSuper = (bool) ($identity['app_superadmin'] ?? false);
        $roles    = self::rankRoles($identity['app_roles'] ?? [], $appSuper);
        if ($roles === []) {
            return ['status' => 'deny', 'message' => self::DENY_MESSAGE, 'revoked' => false];
        }

        return [
            'status'          => 'grant',
            'roles'           => $roles,
            'superadmin'      => $appSuper,
            'console_user_id' => $consoleUserId,
            'authz_version'   => self::intOrNull($identity['authz_version'] ?? null, true),
        ];
    }

    /**
     * @param array<string,mixed> $data user-access pull `data`
     * @return array<string,mixed>
     */
    public static function fromUserAccess(array $data): array
    {
        $exists = (bool) ($data['exists'] ?? false);
        $active = (bool) ($data['active'] ?? false);
        $access = (bool) ($data['app_access'] ?? false);
        if (! $exists || ! $active || ! $access) {
            return ['status' => 'deny', 'message' => self::REVOKED_MESSAGE, 'revoked' => true];
        }

        $appSuper = (bool) ($data['app_superadmin'] ?? false);
        $roles    = self::rankRoles($data['app_roles'] ?? [], $appSuper);
        if ($roles === []) {
            return ['status' => 'deny', 'message' => self::DENY_MESSAGE, 'revoked' => true];
        }

        return [
            'status'          => 'grant',
            'roles'           => $roles,
            'superadmin'      => $appSuper,
            'console_user_id' => self::intOrNull($data['console_user_id'] ?? null),
            'authz_version'   => self::intOrNull($data['authz_version'] ?? null, true),
        ];
    }

    /**
     * Known slugs only, highest rank first. A Console app superadmin always
     * holds super_admin.
     *
     * @return list<string>
     */
    public static function rankRoles(mixed $appRoles, bool $appSuperadmin): array
    {
        $given = [];
        if (is_array($appRoles)) {
            foreach ($appRoles as $r) {
                if (is_string($r)) {
                    $given[] = strtolower(trim($r));
                }
            }
        }
        if ($appSuperadmin) {
            $given[] = self::SUPER_ADMIN;
        }

        return array_values(array_intersect(self::RANKED, array_unique($given)));
    }

    /** The single role Reach should hold for a ranked list, or null. */
    public static function highest(array $rankedRoles): ?string
    {
        $ranked = self::rankRoles($rankedRoles, false);

        return $ranked[0] ?? null;
    }

    /** True when roles were confirmed by Console less than GRACE_SECONDS ago. */
    public static function withinGrace(?string $rolesSyncedAt, ?int $now = null): bool
    {
        if ($rolesSyncedAt === null || trim($rolesSyncedAt) === '') {
            return false;
        }
        $ts = strtotime($rolesSyncedAt);
        if ($ts === false) {
            return false;
        }
        $now ??= time();

        return ($now - $ts) < self::GRACE_SECONDS && $ts <= $now + 300;
    }

    /** @param array<string,mixed> $identity */
    private static function consoleUserId(array $identity): ?int
    {
        $id = self::intOrNull($identity['console_user_id'] ?? null);
        if ($id !== null) {
            return $id;
        }
        $user = is_array($identity['user'] ?? null) ? $identity['user'] : [];

        return self::intOrNull($user['id'] ?? null);
    }

    private static function intOrNull(mixed $v, bool $allowZero = false): ?int
    {
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }
        if (! is_int($v)) {
            return null;
        }

        return ($v > 0 || ($allowZero && $v === 0)) ? $v : null;
    }
}
