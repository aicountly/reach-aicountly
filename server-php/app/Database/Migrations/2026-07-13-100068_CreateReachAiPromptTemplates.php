<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_prompt_templates and reach_ai_prompt_versions.
 * Prompt versions are immutable after creation (no updated_at on versions table).
 */
class CreateReachAiPromptTemplates extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_prompt_templates (
                id                  BIGSERIAL PRIMARY KEY,
                uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
                name                VARCHAR(256) NOT NULL,
                slug                VARCHAR(256) NOT NULL,
                description         TEXT,
                task_type           VARCHAR(64) NOT NULL,
                content_type        VARCHAR(64),
                market_id           BIGINT,
                language            VARCHAR(8) NOT NULL DEFAULT 'en',
                risk_level          VARCHAR(16),
                status              VARCHAR(32) NOT NULL DEFAULT 'draft',
                current_version_id  BIGINT,
                approved_at         TIMESTAMPTZ,
                approved_by         BIGINT,
                created_actor_type  VARCHAR(32) NOT NULL DEFAULT 'human',
                created_by_user_id  BIGINT,
                updated_by_user_id  BIGINT,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at          TIMESTAMPTZ
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_prompt_templates
            ADD CONSTRAINT reach_ai_prompt_templates_status_chk
            CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_prompt_templates_slug ON reach_ai_prompt_templates (slug) WHERE deleted_at IS NULL");
        $this->db->query("CREATE UNIQUE INDEX uq_ai_prompt_templates_uuid ON reach_ai_prompt_templates (uuid)");
        $this->db->query("CREATE INDEX idx_ai_prompt_templates_task ON reach_ai_prompt_templates (task_type, content_type) WHERE status = 'approved' AND deleted_at IS NULL");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_prompt_versions (
                id                       BIGSERIAL PRIMARY KEY,
                uuid                     UUID NOT NULL DEFAULT gen_random_uuid(),
                prompt_template_id       BIGINT NOT NULL REFERENCES reach_ai_prompt_templates(id),
                version_number           INT NOT NULL,
                system_template          TEXT NOT NULL,
                user_template            TEXT NOT NULL,
                variable_schema_json     JSONB NOT NULL DEFAULT '{}',
                output_schema_json       JSONB NOT NULL DEFAULT '{}',
                generation_defaults_json JSONB NOT NULL DEFAULT '{}',
                change_summary           VARCHAR(512),
                status                   VARCHAR(32) NOT NULL DEFAULT 'draft',
                created_actor_type       VARCHAR(32) NOT NULL DEFAULT 'human',
                created_by_user_id       BIGINT,
                approved_at              TIMESTAMPTZ,
                approved_by              BIGINT,
                created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_prompt_versions
            ADD CONSTRAINT reach_ai_prompt_versions_status_chk
            CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_prompt_versions_num ON reach_ai_prompt_versions (prompt_template_id, version_number)");
        $this->db->query("CREATE UNIQUE INDEX uq_ai_prompt_versions_uuid ON reach_ai_prompt_versions (uuid)");
        $this->db->query("CREATE INDEX idx_ai_prompt_versions_approved ON reach_ai_prompt_versions (prompt_template_id, status) WHERE status = 'approved'");

        $this->db->query("ALTER TABLE reach_ai_prompt_templates
            ADD CONSTRAINT fk_prompt_current_version
            FOREIGN KEY (current_version_id) REFERENCES reach_ai_prompt_versions(id) DEFERRABLE INITIALLY DEFERRED
        ");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE reach_ai_prompt_templates DROP CONSTRAINT IF EXISTS fk_prompt_current_version");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_prompt_versions");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_prompt_templates");
    }
}
