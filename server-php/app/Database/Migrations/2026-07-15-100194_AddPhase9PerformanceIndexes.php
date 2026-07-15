<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 9 performance indexes.
 *
 * All indexes are created CONCURRENTLY-safe (IF NOT EXISTS) to allow
 * future application without full table lock.
 */
class AddPhase9PerformanceIndexes extends Migration
{
    public function up(): void
    {
        $indexes = [
            // Refresh recommendation backlog queries
            "CREATE INDEX IF NOT EXISTS idx_refresh_rec_content_status
             ON reach_refresh_recommendations (content_identity_id, status)
             WHERE status NOT IN ('rejected','superseded','expired')",

            // Refresh workflow active queries
            "CREATE INDEX IF NOT EXISTS idx_refresh_wf_tenant_active
             ON reach_refresh_workflows (tenant_id, status)
             WHERE status NOT IN ('outcome_recorded','rejected','cancelled','withdrawn','superseded','failed')",

            // Evidence snapshot freshness check
            "CREATE INDEX IF NOT EXISTS idx_refresh_evidence_identity_date
             ON reach_refresh_evidence_snapshots (content_identity_id, evidence_date DESC)",

            // Attribution journey lookup
            "CREATE INDEX IF NOT EXISTS idx_attribution_jc_model_version
             ON reach_attribution_journey_calculations (model_version_id, calculated_at)",

            // Outcome window pending measurement
            "CREATE INDEX IF NOT EXISTS idx_refresh_outcome_windows_pending
             ON reach_refresh_outcome_windows (measurement_status, post_to)
             WHERE measurement_status = 'pending'",

            // Readiness findings open blockers
            "CREATE INDEX IF NOT EXISTS idx_readiness_findings_blocker
             ON reach_readiness_findings (resolution_status, severity)
             WHERE severity IN ('critical','high') AND resolution_status IN ('open','in_progress')",

            // Attribution allocation touchpoint lookup
            "CREATE INDEX IF NOT EXISTS idx_attribution_af_weight
             ON reach_attribution_allocation_facts (touchpoint_id, allocation_weight)",

            // Content identity first_published_at for detection job
            "CREATE INDEX IF NOT EXISTS idx_content_identities_published_at
             ON reach_content_identities (tenant_id, content_type, first_published_at)
             WHERE publication_status = 'published'",
        ];

        foreach ($indexes as $sql) {
            $this->db->query($sql);
        }
    }

    public function down(): void
    {
        $names = [
            'idx_refresh_rec_content_status',
            'idx_refresh_wf_tenant_active',
            'idx_refresh_evidence_identity_date',
            'idx_attribution_jc_model_version',
            'idx_refresh_outcome_windows_pending',
            'idx_readiness_findings_blocker',
            'idx_attribution_af_weight',
            'idx_content_identities_published_at',
        ];
        foreach ($names as $name) {
            $this->db->query("DROP INDEX IF EXISTS {$name}");
        }
    }
}
