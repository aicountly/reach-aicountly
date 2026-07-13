<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_generation_runs — individual provider attempts.
 * Raw provider responses are NOT stored here; only safe hashes and IDs.
 */
class CreateReachAiGenerationRuns extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_generation_runs (
                id                        BIGSERIAL PRIMARY KEY,
                uuid                      UUID NOT NULL DEFAULT gen_random_uuid(),
                generation_request_id     BIGINT NOT NULL REFERENCES reach_ai_generation_requests(id),
                attempt_number            INT NOT NULL DEFAULT 1,
                provider_id               BIGINT NOT NULL REFERENCES reach_ai_providers(id),
                model_id                  BIGINT NOT NULL REFERENCES reach_ai_models(id),
                prompt_version_id         BIGINT REFERENCES reach_ai_prompt_versions(id),
                grounding_snapshot_id     BIGINT,
                status                    VARCHAR(32) NOT NULL DEFAULT 'pending',
                started_at                TIMESTAMPTZ,
                completed_at              TIMESTAMPTZ,
                duration_ms               INT,
                provider_response_id      VARCHAR(256),
                input_tokens              INT,
                output_tokens             INT,
                total_tokens              INT,
                estimated_cost            NUMERIC(18,8),
                currency                  VARCHAR(8) NOT NULL DEFAULT 'USD',
                fallback_used             BOOLEAN NOT NULL DEFAULT FALSE,
                retryable_error           BOOLEAN,
                error_category            VARCHAR(64),
                error_code                VARCHAR(64),
                redacted_error_message    TEXT,
                request_hash              VARCHAR(64),
                response_hash             VARCHAR(64),
                created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_generation_runs
            ADD CONSTRAINT reach_ai_gen_runs_status_chk
            CHECK (status IN ('pending','running','completed','failed','cancelled','timeout'))
        ");

        $this->db->query("ALTER TABLE reach_ai_generation_runs
            ADD CONSTRAINT reach_ai_gen_runs_error_cat_chk
            CHECK (error_category IS NULL OR error_category IN (
                'configuration_error','authentication_error','rate_limited','timeout',
                'provider_unavailable','network_error','invalid_request','context_limit',
                'malformed_output','schema_validation_error','content_blocked',
                'budget_blocked','cancelled','unknown'
            ))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_gen_runs_uuid ON reach_ai_generation_runs (uuid)");
        $this->db->query("CREATE INDEX idx_ai_gen_runs_request ON reach_ai_generation_runs (generation_request_id)");
        $this->db->query("CREATE INDEX idx_ai_gen_runs_status ON reach_ai_generation_runs (status) WHERE status = 'running'");
        $this->db->query("CREATE UNIQUE INDEX uq_ai_gen_runs_attempt ON reach_ai_generation_runs (generation_request_id, attempt_number)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_generation_runs");
    }
}
