<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAiVisibilityResponses extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_ai_visibility_responses (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                run_id                  BIGINT NOT NULL REFERENCES reach_ai_visibility_runs(id) ON DELETE RESTRICT,
                raw_response            TEXT NOT NULL,
                response_timestamp      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                parser_version          VARCHAR(20) NOT NULL DEFAULT '1.0',
                parse_status            VARCHAR(20) NOT NULL DEFAULT 'pending'
                                        CHECK (parse_status IN ('pending','parsed','failed','skipped')),
                tokens_used             INT,
                token_breakdown         JSONB,
                retention_expires_at    TIMESTAMPTZ,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_visibility_response_uuid UNIQUE (uuid),
                CONSTRAINT uq_visibility_response_run UNIQUE (run_id)
            )
        ");
        $this->db->query("CREATE INDEX idx_visibility_responses_run ON reach_ai_visibility_responses (run_id)");
        $this->db->query("CREATE INDEX idx_visibility_responses_retention ON reach_ai_visibility_responses (retention_expires_at) WHERE retention_expires_at IS NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_visibility_responses CASCADE");
    }
}
