<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-product Knowledge Base cadence (Claude-routine route only).
 * Tiers per superadmin decision 2026-08-05: big = 4 articles/day,
 * normal = 2, small = 1. Products limited to those authenticating via
 * my.aicountly.com / ourpeople.work (excludes the .org family and
 * partner.aicountly.com).
 */
class CreateReachKbProductCadence extends Migration
{
    private const SEED = [
        ['books', 'big', 4], ['hrms', 'big', 4],
        ['financial-reporting', 'normal', 2], ['auditor', 'normal', 2],
        ['secretarial', 'normal', 2], ['our-people', 'normal', 2],
        ['contacts', 'small', 1], ['calendar', 'small', 1],
        ['chat', 'small', 1], ['docs', 'small', 1],
        ['vault', 'small', 1], ['manage', 'small', 1], ['buddy', 'small', 1],
    ];

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_kb_product_cadence (
                id           BIGSERIAL PRIMARY KEY,
                product_slug VARCHAR(100) NOT NULL UNIQUE,
                tier         VARCHAR(8) NOT NULL CHECK (tier IN ('big','normal','small')),
                daily_quota  INT NOT NULL DEFAULT 1,
                enabled      BOOLEAN NOT NULL DEFAULT TRUE,
                updated_by   BIGINT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        foreach (self::SEED as [$slug, $tier, $quota]) {
            $this->db->query(
                'INSERT INTO reach_kb_product_cadence (product_slug, tier, daily_quota) VALUES (?, ?, ?) ON CONFLICT (product_slug) DO NOTHING',
                [$slug, $tier, $quota]
            );
        }

        // KB drafts are keyed to content-base topics directly on the item.
        $this->db->query('ALTER TABLE reach_content_items ADD COLUMN IF NOT EXISTS content_base_key VARCHAR(120)');
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_content_items_base_key ON reach_content_items(content_base_key) WHERE content_base_key IS NOT NULL');
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_kb_product_cadence', true);
        $this->db->query('DROP INDEX IF EXISTS uq_content_items_base_key');
        $this->db->query('ALTER TABLE reach_content_items DROP COLUMN IF EXISTS content_base_key');
    }
}
