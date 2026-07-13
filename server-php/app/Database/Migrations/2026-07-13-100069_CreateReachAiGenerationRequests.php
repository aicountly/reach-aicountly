<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_generation_requests — the business-level generation record.
 */
class CreateReachAiGenerationRequests extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_generation_requests (
                id                       BIGSERIAL PRIMARY KEY,
                uuid                     UUID NOT NULL DEFAULT gen_random_uuid(),
                content_item_id          BIGINT REFERENCES reach_content_items(id),
                source_content_version_id BIGINT,
                daily_pack_id            BIGINT,
                daily_pack_item_id       BIGINT,
                task_type                VARCHAR(64) NOT NULL,
                content_type             VARCHAR(64) NOT NULL,
                prompt_version_id        BIGINT REFERENCES reach_ai_prompt_versions(id),
                requested_provider_id    BIGINT REFERENCES reach_ai_providers(id),
                requested_model_id       BIGINT REFERENCES reach_ai_models(id),
                status                   VARCHAR(32) NOT NULL DEFAULT 'pending',
                priority                 INT NOT NULL DEFAULT 0,
                idempotency_key          VARCHAR(128),
                request_parameters_json  JSONB NOT NULL DEFAULT '{}',
                requested_actor_type     VARCHAR(32) NOT NULL DEFAULT 'human',
                requested_by_user_id     BIGINT,
                requested_by_service     VARCHAR(64),
                request_id               VARCHAR(64),
                job_id                   BIGINT,
                created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                completed_at             TIMESTAMPTZ,
                cancelled_at             TIMESTAMPTZ
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_generation_requests
            ADD CONSTRAINT reach_ai_gen_requests_status_chk
            CHECK (status IN ('pending','grounding','queued','processing','validating','completed','failed','cancelled','blocked'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_gen_requests_idempotency ON reach_ai_generation_requests (idempotency_key) WHERE idempotency_key IS NOT NULL");
        $this->db->query("CREATE UNIQUE INDEX uq_ai_gen_requests_uuid ON reach_ai_generation_requests (uuid)");
        $this->db->query("CREATE INDEX idx_ai_gen_requests_content_item ON reach_ai_generation_requests (content_item_id) WHERE content_item_id IS NOT NULL");
        $this->db->query("CREATE INDEX idx_ai_gen_requests_status ON reach_ai_generation_requests (status) WHERE status NOT IN ('completed','cancelled')");
        $this->db->query("CREATE INDEX idx_ai_gen_requests_job ON reach_ai_generation_requests (job_id) WHERE job_id IS NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_generation_requests");
    }
}
