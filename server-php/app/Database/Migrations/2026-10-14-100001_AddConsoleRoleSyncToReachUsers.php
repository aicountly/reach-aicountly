<?php

namespace App\Database\Migrations;

use App\Database\Seeds\RolesAndPermissionsSeeder;
use CodeIgniter\Database\Migration;

/**
 * Staff roles come from Console (console.aicountly.org → Roles & Access).
 *
 * - reach_users gains the Console link and sync bookkeeping:
 *     console_user_id — Console user id (used for the user-access pull)
 *     roles_synced_at — last time Console confirmed the role (12h grace
 *                       window when Console is unreachable at refresh)
 *     authz_version   — Console's authz_version at that sync
 * - the four roles Console can assign for app_code `reach` (super_admin,
 *   reach_admin, marketing_manager, content_reviewer) are inserted when
 *   missing, with the canonical permissions from RolesAndPermissionsSeeder.
 *   Existing rows are left exactly as they are. InitialReachSeeder only runs
 *   on the first deploy, so this guarantees every role Console assigns
 *   resolves locally.
 *
 * Raw DDL with IF NOT EXISTS: re-runs are harmless, and it sidesteps the
 * connection's cached field list (see MigrationIdempotencyTest).
 */
class AddConsoleRoleSyncToReachUsers extends Migration
{
    private const CONSOLE_ROLES = ['super_admin', 'reach_admin', 'marketing_manager', 'content_reviewer'];

    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_users ADD COLUMN IF NOT EXISTS console_user_id BIGINT NULL');
        $this->db->query('ALTER TABLE reach_users ADD COLUMN IF NOT EXISTS roles_synced_at TIMESTAMPTZ NULL');
        $this->db->query('ALTER TABLE reach_users ADD COLUMN IF NOT EXISTS authz_version INTEGER NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS reach_users_console_user_id_idx ON reach_users (console_user_id)');

        foreach (RolesAndPermissionsSeeder::roleDefinitions() as $role) {
            if (! in_array($role['slug'], self::CONSOLE_ROLES, true)) {
                continue;
            }
            $this->db->query(
                'INSERT INTO reach_roles (slug, name, description, permissions, created_at, updated_at)
                 VALUES (?, ?, ?, ?::jsonb, NOW(), NOW())
                 ON CONFLICT (slug) DO NOTHING',
                [$role['slug'], $role['name'], $role['description'], json_encode(array_values($role['permissions']))],
            );
        }
    }

    public function down(): void
    {
        // Role rows are left in place: users may reference them (FK RESTRICT).
        $this->db->query('DROP INDEX IF EXISTS reach_users_console_user_id_idx');
        foreach (['authz_version', 'roles_synced_at', 'console_user_id'] as $col) {
            $this->db->query("ALTER TABLE reach_users DROP COLUMN IF EXISTS {$col}");
        }
    }
}
