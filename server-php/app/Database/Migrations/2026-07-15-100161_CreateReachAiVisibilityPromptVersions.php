<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAiVisibilityPromptVersions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_ai_visibility_prompt_versions (
                id              BIGSERIAL PRIMARY KEY,
                uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
                prompt_id       BIGINT NOT NULL REFERENCES reach_ai_visibility_prompts(id) ON DELETE RESTRICT,
                version_number  INT NOT NULL,
                prompt_text     TEXT NOT NULL,
                content_hash    VARCHAR(64) NOT NULL,
                is_active       BOOLEAN NOT NULL DEFAULT FALSE,
                approved_at     TIMESTAMPTZ,
                approved_by     BIGINT,
                created_by      BIGINT,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_visibility_prompt_version_uuid UNIQUE (uuid),
                CONSTRAINT uq_visibility_prompt_version UNIQUE (prompt_id, version_number),
                CONSTRAINT uq_visibility_prompt_hash UNIQUE (prompt_id, content_hash)
            )
        ");
        $this->db->query("CREATE INDEX idx_visibility_prompt_versions_prompt ON reach_ai_visibility_prompt_versions (prompt_id, is_active)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_visibility_prompt_versions CASCADE");
    }
}
