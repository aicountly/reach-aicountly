<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 2 — Extend reach_approvals for content items and multi-stage workflow.
 *
 * 1. Drop/recreate subject_type CHECK to add 'content_item' and 'daily_pack'.
 * 2. Add 'stage' column (e.g. editorial_review, subject_matter_review, etc.)
 * 3. Add 'stage_config' JSONB for per-stage configuration snapshots.
 *
 * Existing rows unaffected — all new values are nullable with NULL defaults.
 */
class ExtendApprovalsForContentItems extends Migration
{
    private const CONSTRAINT = 'reach_approvals_subject_check';

    public function up(): void
    {
        // Extend subject_type CHECK to include Phase 2 types
        $this->db->query(
            "ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS " . self::CONSTRAINT
        );
        $this->db->query(
            "ALTER TABLE reach_approvals ADD CONSTRAINT " . self::CONSTRAINT . " "
            . "CHECK (subject_type IN ("
            . "'blog','campaign','social','email','whatsapp','landing','bot','other',"
            . "'product','module','feature','product_claim','evidence',"
            . "'source','citation','brand_rule','content_policy','search_intent',"
            . "'content_item','daily_pack'"
            . "))"
        );

        // Add multi-stage columns
        $this->db->query(
            "ALTER TABLE reach_approvals "
            . "ADD COLUMN IF NOT EXISTS stage VARCHAR(32) DEFAULT NULL"
        );
        $this->db->query(
            "ALTER TABLE reach_approvals "
            . "ADD COLUMN IF NOT EXISTS stage_config JSONB DEFAULT NULL"
        );

        // Stage CHECK constraint
        $this->db->query(
            "ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS reach_approvals_stage_chk"
        );
        $this->db->query(
            "ALTER TABLE reach_approvals ADD CONSTRAINT reach_approvals_stage_chk "
            . "CHECK (stage IS NULL OR stage IN ("
            . "'editorial_review','subject_matter_review','compliance_review','final_approval'"
            . "))"
        );

        // Index for stage lookups
        $this->db->query(
            "CREATE INDEX IF NOT EXISTS ra_stage_idx ON reach_approvals (stage) WHERE stage IS NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query("DROP INDEX IF EXISTS ra_stage_idx");
        $this->db->query("ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS reach_approvals_stage_chk");
        $this->db->query("ALTER TABLE reach_approvals DROP COLUMN IF EXISTS stage_config");
        $this->db->query("ALTER TABLE reach_approvals DROP COLUMN IF EXISTS stage");

        // Restore Phase 1 constraint
        $this->db->query(
            "ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS " . self::CONSTRAINT
        );
        $this->db->query(
            "ALTER TABLE reach_approvals ADD CONSTRAINT " . self::CONSTRAINT . " "
            . "CHECK (subject_type IN ("
            . "'blog','campaign','social','email','whatsapp','landing','bot','other',"
            . "'product','module','feature','product_claim','evidence',"
            . "'source','citation','brand_rule','content_policy','search_intent'"
            . "))"
        );
    }
}
