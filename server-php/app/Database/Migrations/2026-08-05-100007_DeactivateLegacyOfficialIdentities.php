<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 100093 seeded 5 generic official identities that were superseded
 * by the 10 role-routed expert desks (2026-07-30-200002). The old rows kept
 * the default role and duplicate coverage — deactivate them so agent
 * dispatch and public profiles only ever use the current set. Rows are kept
 * (not deleted) because historical answers may reference them.
 */
class DeactivateLegacyOfficialIdentities extends Migration
{
    private const LEGACY_SLUGS = [
        'aicountly-official',
        'aicountly-support',
        'aicountly-product',
        'aicountly-compliance',
        'aicountly-engineering',
    ];

    public function up(): void
    {
        $this->db->table('reach_community_official_identities')
            ->whereIn('slug', self::LEGACY_SLUGS)
            ->update(['is_active' => false, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        $this->db->table('reach_community_official_identities')
            ->whereIn('slug', self::LEGACY_SLUGS)
            ->update(['is_active' => true, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
