<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAiVisibilityPrompts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_ai_visibility_prompts (
                id              BIGSERIAL PRIMARY KEY,
                uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id       BIGINT NOT NULL,
                name            VARCHAR(160) NOT NULL,
                topic           VARCHAR(240) NOT NULL,
                intent          VARCHAR(240),
                persona         VARCHAR(120),
                locale          VARCHAR(10) NOT NULL DEFAULT 'en',
                product_id      BIGINT,
                purpose         VARCHAR(40) NOT NULL DEFAULT 'ai_visibility_monitoring'
                                CHECK (purpose = 'ai_visibility_monitoring'),
                schedule_cron   VARCHAR(80),
                status          VARCHAR(20) NOT NULL DEFAULT 'draft'
                                CHECK (status IN ('draft','active','paused','archived')),
                created_by      BIGINT,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_visibility_prompt_uuid UNIQUE (uuid),
                CONSTRAINT uq_visibility_prompt_name UNIQUE (tenant_id, name)
            )
        ");
        $this->db->query("CREATE INDEX idx_visibility_prompts_tenant ON reach_ai_visibility_prompts (tenant_id, status)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_visibility_prompts CASCADE");
    }
}
