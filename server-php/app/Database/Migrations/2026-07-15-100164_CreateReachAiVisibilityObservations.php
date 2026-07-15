<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAiVisibilityObservations extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_ai_visibility_observations (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                response_id             BIGINT NOT NULL REFERENCES reach_ai_visibility_responses(id) ON DELETE RESTRICT,
                run_id                  BIGINT NOT NULL REFERENCES reach_ai_visibility_runs(id) ON DELETE RESTRICT,
                entity_mentioned        VARCHAR(120) NOT NULL,
                mention_type            VARCHAR(20) NOT NULL CHECK (mention_type IN ('brand','product','competitor','domain','unknown')),
                mention_order           INT,
                sentiment_classification VARCHAR(20) CHECK (sentiment_classification IN ('positive','neutral','negative','mixed','unclear')),
                coverage_state          VARCHAR(20) NOT NULL DEFAULT 'mentioned'
                                        CHECK (coverage_state IN ('mentioned','not_mentioned','uncertain')),
                confidence              NUMERIC(4,3) CHECK (confidence BETWEEN 0.0 AND 1.0),
                evidence_excerpt        TEXT,
                parser_finding          JSONB,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_visibility_observation_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_visibility_observations_response ON reach_ai_visibility_observations (response_id)");
        $this->db->query("CREATE INDEX idx_visibility_observations_run ON reach_ai_visibility_observations (run_id)");
        $this->db->query("CREATE INDEX idx_visibility_observations_entity ON reach_ai_visibility_observations (entity_mentioned)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_visibility_observations CASCADE");
    }
}
