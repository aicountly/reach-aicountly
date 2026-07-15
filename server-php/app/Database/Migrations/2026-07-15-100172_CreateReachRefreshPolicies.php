<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshPolicies extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_policies (
                id          BIGSERIAL PRIMARY KEY,
                uuid        UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id   BIGINT NOT NULL REFERENCES reach_actors(id),
                name        VARCHAR(200) NOT NULL,
                content_type VARCHAR(50) NOT NULL
                    CHECK (content_type IN ('blog','knowledge_base','community_answer','video','campaign')),
                is_active   BOOLEAN NOT NULL DEFAULT FALSE,
                created_by  BIGINT REFERENCES reach_actors(id),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, name)
            )
        ");
        $this->db->query("CREATE UNIQUE INDEX reach_refresh_policies_uuid ON reach_refresh_policies (uuid)");
        $this->db->query("CREATE INDEX reach_refresh_policies_tenant_type ON reach_refresh_policies (tenant_id, content_type, is_active)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_policies CASCADE");
    }
}
