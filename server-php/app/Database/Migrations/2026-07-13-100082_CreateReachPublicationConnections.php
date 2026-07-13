<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationConnections extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_connections (
                id                      BIGSERIAL PRIMARY KEY,
                connection_key          VARCHAR(64) NOT NULL UNIQUE,
                display_name            VARCHAR(255) NOT NULL,
                base_url                VARCHAR(2048) NOT NULL,
                api_version             SMALLINT NOT NULL DEFAULT 1,
                authentication_type     VARCHAR(32) NOT NULL DEFAULT 'hmac_bearer'
                                        CHECK (authentication_type IN ('hmac_bearer','bearer_only','none')),
                secret_env_reference        VARCHAR(128),
                signing_key_env_reference   VARCHAR(128),
                key_id_env_reference        VARCHAR(128),
                timeout_seconds         SMALLINT NOT NULL DEFAULT 15,
                max_retries             SMALLINT NOT NULL DEFAULT 5,
                enabled                 BOOLEAN NOT NULL DEFAULT TRUE,
                health_status           VARCHAR(32) NOT NULL DEFAULT 'unknown'
                                        CHECK (health_status IN ('unknown','healthy','degraded','unhealthy')),
                last_health_checked_at  TIMESTAMPTZ,
                last_health_error       TEXT,
                supported_content_types JSONB NOT NULL DEFAULT '[\"blog\",\"knowledge_base\"]'::jsonb,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_pub_connections_key ON reach_publication_connections(connection_key)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_pub_connections_enabled ON reach_publication_connections(enabled)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_publication_connections CASCADE');
    }
}
