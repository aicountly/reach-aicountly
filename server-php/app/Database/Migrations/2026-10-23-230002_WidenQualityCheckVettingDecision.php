<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * reach_content_quality_checks.vetting_decision was VARCHAR(16), but
 * RoutineVetting reports `revise_unresolved` (17), so a re-vet that ended
 * there was not recorded ("value too long for type character varying(16)").
 */
class WidenQualityCheckVettingDecision extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_content_quality_checks ALTER COLUMN vetting_decision TYPE VARCHAR(32)');
    }

    public function down(): void
    {
        // Narrowing would fail on stored `revise_unresolved` rows; leave it wide.
    }
}
