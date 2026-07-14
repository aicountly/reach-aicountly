<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPhase7FieldsToCampaigns extends Migration
{
    public function up(): void
    {
        // Add uuid, tenant_id, lock_version
        $this->db->query('ALTER TABLE reach_campaigns ADD COLUMN IF NOT EXISTS uuid UUID DEFAULT gen_random_uuid() UNIQUE');
        $this->db->query('ALTER TABLE reach_campaigns ADD COLUMN IF NOT EXISTS tenant_id BIGINT');
        $this->db->query('ALTER TABLE reach_campaigns ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0');

        // Update uuid for existing rows
        $this->db->query("UPDATE reach_campaigns SET uuid = gen_random_uuid() WHERE uuid IS NULL");

        // Extend status CHECK to include dispatch-lifecycle states
        $this->db->query('ALTER TABLE reach_campaigns DROP CONSTRAINT IF EXISTS reach_campaigns_status_check');
        $this->db->query(
            "ALTER TABLE reach_campaigns ADD CONSTRAINT reach_campaigns_status_check CHECK (status IN ("
            . "'draft','active','completed','paused','cancelled','archived',"
            . "'preparing','ready_for_review','in_review','approved','scheduled','dispatching',"
            . "'partially_completed','failed','dead_lettered','expired',"
            . "'changes_requested','rejected','withdrawn'"
            . "))"
        );

        $this->db->query('CREATE INDEX IF NOT EXISTS idx_campaigns_uuid ON reach_campaigns(uuid)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_campaigns_tenant ON reach_campaigns(tenant_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_campaigns_tenant');
        $this->db->query('DROP INDEX IF EXISTS idx_campaigns_uuid');
        $this->db->query('ALTER TABLE reach_campaigns DROP COLUMN IF EXISTS lock_version');
        $this->db->query('ALTER TABLE reach_campaigns DROP COLUMN IF EXISTS tenant_id');
        $this->db->query('ALTER TABLE reach_campaigns DROP COLUMN IF EXISTS uuid');
    }
}
