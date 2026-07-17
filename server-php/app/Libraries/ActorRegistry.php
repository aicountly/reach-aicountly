<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Maps reach_users principals to reach_actors registry rows.
 *
 * Phase 3+ tables store created_by / reviewed_by as FKs to reach_actors.
 * Human operators use the same numeric id in both tables.
 */
class ActorRegistry
{
    public static function idForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $db = \Config\Database::connect();

        if ($db->table('reach_actors')->where('id', $userId)->countAllResults() > 0) {
            return $userId;
        }

        $user = $db->table('reach_users')->where('id', $userId)->get()->getRowArray();
        if ($user === null) {
            return null;
        }

        $db->query(
            'INSERT INTO reach_actors (id, actor_type, display_name, email, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (id) DO NOTHING',
            [
                $userId,
                $user['actor_type'] ?? 'human',
                $user['name'] ?? null,
                $user['email'] ?? null,
                (bool) ($user['is_active'] ?? true),
            ]
        );

        $db->query(
            "SELECT setval(
                pg_get_serial_sequence('reach_actors', 'id'),
                GREATEST((SELECT COALESCE(MAX(id), 1) FROM reach_actors), 1)
             )"
        );

        return $userId;
    }
}
