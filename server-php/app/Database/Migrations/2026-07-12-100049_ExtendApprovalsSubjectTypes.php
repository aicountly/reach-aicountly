<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extend the reach_approvals.subject_type CHECK constraint to include
 * Phase 1 knowledge entities.
 *
 * PostgreSQL does not support ALTER CONSTRAINT directly, so we drop and
 * recreate the check constraint. Existing rows are unaffected because the
 * original values remain valid in the new set.
 */
class ExtendApprovalsSubjectTypes extends Migration
{
    private const CONSTRAINT = 'reach_approvals_subject_check';

    public function up(): void
    {
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

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS " . self::CONSTRAINT
        );
        $this->db->query(
            "ALTER TABLE reach_approvals ADD CONSTRAINT " . self::CONSTRAINT . " "
            . "CHECK (subject_type IN ('blog','campaign','social','email','whatsapp','landing','bot','other'))"
        );
    }
}
