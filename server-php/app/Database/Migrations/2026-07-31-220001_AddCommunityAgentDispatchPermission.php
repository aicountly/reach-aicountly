<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 5 remediation — registers `community_agent.dispatch`, the permission
 * gating the newly-wired role-routed operational agent runtime HTTP endpoint
 * (POST /community/agents/dispatch). Additive-only, mirrors 100103.
 *
 * Granting the permission to a role still requires re-running
 * RolesAndPermissionsSeeder (community_automation_admin now includes it) —
 * this migration only records the slug for the documentation registry.
 */
class AddCommunityAgentDispatchPermission extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "INSERT INTO reach_community_permission_registry (slug, phase) VALUES (?, ?) ON CONFLICT (slug) DO NOTHING",
            ['community_agent.dispatch', 'phase5_remediation']
        );
    }

    public function down(): void
    {
        $this->db->query(
            'DELETE FROM reach_community_permission_registry WHERE slug = ?',
            ['community_agent.dispatch']
        );
    }
}
