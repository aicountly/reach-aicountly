<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAiVisibilityRuns extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_ai_visibility_runs (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id               BIGINT NOT NULL,
                prompt_version_id       BIGINT NOT NULL REFERENCES reach_ai_visibility_prompt_versions(id) ON DELETE RESTRICT,
                run_type                VARCHAR(20) NOT NULL DEFAULT 'scheduled'
                                        CHECK (run_type IN ('scheduled','manual_test','backfill')),
                ai_route                VARCHAR(80),
                ai_model                VARCHAR(80),
                ai_provider             VARCHAR(40),
                status                  VARCHAR(20) NOT NULL DEFAULT 'queued'
                                        CHECK (status IN ('queued','running','completed','failed','cancelled')),
                execution_budget_cents  INT,
                actual_cost_cents       INT,
                tokens_used             INT,
                job_id                  BIGINT,
                queued_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                started_at              TIMESTAMPTZ,
                completed_at            TIMESTAMPTZ,
                error_message           TEXT,
                CONSTRAINT uq_visibility_run_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_visibility_runs_tenant ON reach_ai_visibility_runs (tenant_id, queued_at DESC)");
        $this->db->query("CREATE INDEX idx_visibility_runs_prompt ON reach_ai_visibility_runs (prompt_version_id)");
        $this->db->query("CREATE INDEX idx_visibility_runs_status ON reach_ai_visibility_runs (status)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_visibility_runs CASCADE");
    }
}
