<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_providers and reach_ai_provider_health tables.
 * Secrets are NEVER stored here; secret_env_reference holds only the env var name.
 */
class CreateReachAiProviders extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_providers (
                id                        BIGSERIAL PRIMARY KEY,
                uuid                      UUID NOT NULL DEFAULT gen_random_uuid(),
                provider_key              VARCHAR(64) NOT NULL,
                display_name              VARCHAR(128) NOT NULL,
                adapter_class             VARCHAR(256) NOT NULL,
                secret_env_reference      VARCHAR(128) NOT NULL DEFAULT '',
                status                    VARCHAR(32) NOT NULL DEFAULT 'draft',
                supports_structured_output BOOLEAN NOT NULL DEFAULT FALSE,
                supports_tool_calls       BOOLEAN NOT NULL DEFAULT FALSE,
                supports_streaming        BOOLEAN NOT NULL DEFAULT FALSE,
                supports_health_check     BOOLEAN NOT NULL DEFAULT FALSE,
                default_timeout_seconds   INT NOT NULL DEFAULT 30,
                default_max_output_tokens INT NOT NULL DEFAULT 4096,
                last_health_status        VARCHAR(32),
                last_health_checked_at    TIMESTAMPTZ,
                configuration_status      VARCHAR(32) NOT NULL DEFAULT 'unconfigured',
                created_actor_type        VARCHAR(32) NOT NULL DEFAULT 'system',
                created_by_user_id        BIGINT,
                updated_by_user_id        BIGINT,
                created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at                TIMESTAMPTZ
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_providers
            ADD CONSTRAINT reach_ai_providers_status_chk
            CHECK (status IN ('draft','enabled','disabled','unhealthy','deprecated','archived'))
        ");

        $this->db->query("ALTER TABLE reach_ai_providers
            ADD CONSTRAINT reach_ai_providers_config_status_chk
            CHECK (configuration_status IN ('unconfigured','configured','invalid'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_providers_key ON reach_ai_providers (provider_key) WHERE deleted_at IS NULL");
        $this->db->query("CREATE UNIQUE INDEX uq_ai_providers_uuid ON reach_ai_providers (uuid)");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_provider_health (
                id                BIGSERIAL PRIMARY KEY,
                provider_id       BIGINT NOT NULL REFERENCES reach_ai_providers(id),
                health_status     VARCHAR(32) NOT NULL DEFAULT 'unknown',
                checked_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                response_time_ms  INT,
                error_message     TEXT,
                circuit_state     VARCHAR(16) NOT NULL DEFAULT 'closed',
                failure_count     INT NOT NULL DEFAULT 0,
                last_failure_at   TIMESTAMPTZ,
                cooldown_until    TIMESTAMPTZ,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_provider_health
            ADD CONSTRAINT reach_ai_provider_health_status_chk
            CHECK (health_status IN ('healthy','degraded','unhealthy','unknown'))
        ");

        $this->db->query("ALTER TABLE reach_ai_provider_health
            ADD CONSTRAINT reach_ai_provider_health_circuit_chk
            CHECK (circuit_state IN ('closed','open','half_open'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_provider_health_provider ON reach_ai_provider_health (provider_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_provider_health");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_providers");
    }
}
