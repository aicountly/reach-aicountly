<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_validation_runs (pipeline run per content version) and
 * reach_ai_validation_findings (individual validator results, linked to Phase 2 content validations).
 */
class CreateReachAiValidationRuns extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_validation_runs (
                id                        BIGSERIAL PRIMARY KEY,
                uuid                      UUID NOT NULL DEFAULT gen_random_uuid(),
                generation_request_id     BIGINT REFERENCES reach_ai_generation_requests(id),
                content_item_id           BIGINT NOT NULL REFERENCES reach_content_items(id),
                content_version_id        BIGINT NOT NULL,
                status                    VARCHAR(32) NOT NULL DEFAULT 'pending',
                blocking_count            INT NOT NULL DEFAULT 0,
                critical_count            INT NOT NULL DEFAULT 0,
                warning_count             INT NOT NULL DEFAULT 0,
                info_count                INT NOT NULL DEFAULT 0,
                started_at                TIMESTAMPTZ,
                completed_at              TIMESTAMPTZ,
                created_actor_type        VARCHAR(32) NOT NULL DEFAULT 'system',
                created_by_user_id        BIGINT,
                request_id                VARCHAR(64),
                created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_validation_runs
            ADD CONSTRAINT reach_ai_val_runs_status_chk
            CHECK (status IN ('pending','running','completed','failed','cancelled'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_val_runs_uuid ON reach_ai_validation_runs (uuid)");
        $this->db->query("CREATE INDEX idx_ai_val_runs_content_item ON reach_ai_validation_runs (content_item_id)");
        $this->db->query("CREATE INDEX idx_ai_val_runs_request ON reach_ai_validation_runs (generation_request_id) WHERE generation_request_id IS NOT NULL");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_validation_findings (
                id                           BIGSERIAL PRIMARY KEY,
                uuid                         UUID NOT NULL DEFAULT gen_random_uuid(),
                validation_run_id            BIGINT NOT NULL REFERENCES reach_ai_validation_runs(id),
                content_validation_id        BIGINT,
                validator_type               VARCHAR(64) NOT NULL,
                validator_class              VARCHAR(256),
                is_ai_assisted               BOOLEAN NOT NULL DEFAULT FALSE,
                severity                     VARCHAR(16) NOT NULL DEFAULT 'info',
                status                       VARCHAR(32) NOT NULL DEFAULT 'passed',
                title                        VARCHAR(256),
                message                      TEXT,
                details_json                 JSONB,
                affected_field               VARCHAR(128),
                suggested_fix                TEXT,
                waiver_reason                TEXT,
                waived_by_user_id            BIGINT,
                waived_at                    TIMESTAMPTZ,
                resolved_at                  TIMESTAMPTZ,
                resolved_by_user_id          BIGINT,
                created_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_validation_findings
            ADD CONSTRAINT reach_ai_val_findings_severity_chk
            CHECK (severity IN ('info','warning','high','critical'))
        ");

        $this->db->query("ALTER TABLE reach_ai_validation_findings
            ADD CONSTRAINT reach_ai_val_findings_status_chk
            CHECK (status IN ('passed','warning','failed','waived','not_applicable','resolved','superseded'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_val_findings_uuid ON reach_ai_validation_findings (uuid)");
        $this->db->query("CREATE INDEX idx_ai_val_findings_run ON reach_ai_validation_findings (validation_run_id)");
        $this->db->query("CREATE INDEX idx_ai_val_findings_severity ON reach_ai_validation_findings (severity, status) WHERE status IN ('failed','warning')");
        $this->db->query("CREATE INDEX idx_ai_val_findings_content_val ON reach_ai_validation_findings (content_validation_id) WHERE content_validation_id IS NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_validation_findings");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_validation_runs");
    }
}
