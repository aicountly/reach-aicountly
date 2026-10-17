<?php

namespace App\Libraries\Automation;

use App\Libraries\ActorRegistry;

/**
 * The account automatic approvals are recorded under. Content the vetting
 * model approves is signed off by "Reach System Bot" — a login-disabled
 * reach_users row — so every approval row still names an approver and the
 * audit trail says plainly that no person approved it. Authorised by the
 * owner on 2026-10-17 for Community, Blogs, Knowledge Base and release notes.
 *
 * userId() is for tables keyed to reach_users (community approvals);
 * actorId() for those keyed to reach_actors (blog content approvals).
 */
final class SystemApprover
{
    public const EMAIL = 'system-bot@reach.local';

    public static function userId(): int
    {
        $db  = \Config\Database::connect();
        $row = $db->table('reach_users')->where('email', self::EMAIL)->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        $roleId = (int) ($db->table('reach_roles')->where('slug', 'super_admin')->get()->getRowArray()['id'] ?? 0);
        if ($roleId <= 0) {
            $roleId = (int) ($db->table('reach_roles')->orderBy('id', 'ASC')->limit(1)->get()->getRowArray()['id'] ?? 0);
        }
        if ($roleId <= 0) {
            throw new \RuntimeException('Cannot approve automatically: no role available to seed the system bot user.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('reach_users')->insert([
            'email'             => self::EMAIL,
            'name'              => 'Reach System Bot',
            'password_hash'     => '',
            'role_id'           => $roleId,
            'is_active'         => false,
            'is_login_disabled' => true,
            'actor_type'        => 'system',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return (int) $db->insertID();
    }

    public static function actorId(): int
    {
        $userId  = self::userId();
        $actorId = ActorRegistry::idForUser($userId);
        if ($actorId === null || $actorId <= 0) {
            throw new \RuntimeException("Cannot approve automatically: system bot user #{$userId} could not be registered in reach_actors.");
        }
        return $actorId;
    }
}
