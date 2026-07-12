<?php

namespace App\Libraries;

use App\Models\RoleModel;
use App\Models\UserModel;
use App\Models\UserPermissionModel;
use Config\Permissions;

/**
 * Computes the effective permission set for a user by merging:
 *   1. Role permissions from reach_roles.permissions JSONB
 *      (wildcard "*" or group wildcards like "blog.*" supported)
 *   2. Per-user grants and denies from reach_user_permissions
 *
 * The result is a plain sorted array of concrete permission slugs, plus
 * an optional leading "*" when the user has full access. This makes both
 * server-side checks and the /v1/me payload trivially cacheable.
 */
class PermissionService
{
    /** @var array<int, string[]> */
    private array $cache = [];

    public function resolveEffective(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $user = (new UserModel())->withRole($userId);
        if (! $user) {
            return $this->cache[$userId] = [];
        }

        $rolePerms = $user['permissions'] ?? null;
        if ($rolePerms === null && isset($user['role_id'])) {
            $role = (new RoleModel())->find((int) $user['role_id']);
            $rolePerms = $role['permissions'] ?? null;
        }
        if (is_string($rolePerms)) {
            $decoded = json_decode($rolePerms, true);
            $rolePerms = is_array($decoded) ? $decoded : [];
        }
        $rolePerms = is_array($rolePerms) ? $rolePerms : [];

        $overrides = (new UserPermissionModel())->overridesFor($userId);

        $set = [];
        foreach ($rolePerms as $p) {
            $set[$p] = true;
        }
        foreach ($overrides['grants'] as $p) {
            $set[$p] = true;
        }
        foreach ($overrides['denies'] as $p) {
            unset($set[$p]);
        }

        $expanded = $this->expandWildcards(array_keys($set));
        sort($expanded);
        return $this->cache[$userId] = $expanded;
    }

    public function hasPermission(int $userId, string $required): bool
    {
        $perms = $this->resolveEffective($userId);
        if (in_array('*', $perms, true)) {
            return true;
        }
        if (in_array($required, $perms, true)) {
            return true;
        }
        $group = explode('.', $required, 2)[0] ?? '';
        if ($group !== '' && in_array($group . '.*', $perms, true)) {
            return true;
        }
        return false;
    }

    /**
     * Expand wildcards for the client-facing effective list, but keep
     * `*` and `group.*` markers so the frontend can also short-circuit.
     * @param string[] $rawPerms
     * @return string[]
     */
    private function expandWildcards(array $rawPerms): array
    {
        if (in_array('*', $rawPerms, true)) {
            $all = Permissions::all();
            array_unshift($all, '*');
            return array_values(array_unique($all));
        }
        $out = [];
        foreach ($rawPerms as $p) {
            if (str_ends_with($p, '.*')) {
                $group = substr($p, 0, -2);
                foreach (Permissions::groups()[$group] ?? [] as $expanded) {
                    $out[] = $expanded;
                }
                $out[] = $p;
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    public function invalidate(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
            return;
        }
        unset($this->cache[$userId]);
    }
}
