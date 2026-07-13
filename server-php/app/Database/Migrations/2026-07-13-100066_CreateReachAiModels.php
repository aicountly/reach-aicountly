<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_models. Pricing fields are editable because provider pricing changes.
 */
class CreateReachAiModels extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_models (
                id                        BIGSERIAL PRIMARY KEY,
                uuid                      UUID NOT NULL DEFAULT gen_random_uuid(),
                provider_id               BIGINT NOT NULL REFERENCES reach_ai_providers(id),
                model_key                 VARCHAR(128) NOT NULL,
                display_name              VARCHAR(128) NOT NULL,
                model_family              VARCHAR(64),
                capability_types_json     JSONB NOT NULL DEFAULT '[]',
                context_limit             INT NOT NULL DEFAULT 8192,
                maximum_output_tokens     INT NOT NULL DEFAULT 4096,
                input_cost_per_unit       NUMERIC(18,8) NOT NULL DEFAULT 0,
                output_cost_per_unit      NUMERIC(18,8) NOT NULL DEFAULT 0,
                cost_unit                 VARCHAR(16) NOT NULL DEFAULT 'per_1k_tokens',
                currency                  VARCHAR(8) NOT NULL DEFAULT 'USD',
                supports_structured_output BOOLEAN NOT NULL DEFAULT FALSE,
                supports_tool_calls       BOOLEAN NOT NULL DEFAULT FALSE,
                enabled                   BOOLEAN NOT NULL DEFAULT FALSE,
                approval_status           VARCHAR(32) NOT NULL DEFAULT 'draft',
                valid_from                TIMESTAMPTZ,
                valid_until               TIMESTAMPTZ,
                created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at                TIMESTAMPTZ
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_models
            ADD CONSTRAINT reach_ai_models_approval_chk
            CHECK (approval_status IN ('draft','approved','deprecated','archived'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_models_key ON reach_ai_models (provider_id, model_key) WHERE deleted_at IS NULL");
        $this->db->query("CREATE UNIQUE INDEX uq_ai_models_uuid ON reach_ai_models (uuid)");
        $this->db->query("CREATE INDEX idx_ai_models_provider ON reach_ai_models (provider_id) WHERE deleted_at IS NULL");
        $this->db->query("CREATE INDEX idx_ai_models_enabled ON reach_ai_models (enabled) WHERE enabled = TRUE AND deleted_at IS NULL");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_models");
    }
}
