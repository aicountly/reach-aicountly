<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_generation_artifacts (schema-validated parsed output) and
 * reach_ai_grounding_snapshots (immutable approved-knowledge context used).
 */
class CreateReachAiGenerationArtifacts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_grounding_snapshots (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                generation_request_id   BIGINT NOT NULL REFERENCES reach_ai_generation_requests(id),
                product_ids_json        JSONB NOT NULL DEFAULT '[]',
                module_ids_json         JSONB NOT NULL DEFAULT '[]',
                feature_ids_json        JSONB NOT NULL DEFAULT '[]',
                persona_ids_json        JSONB NOT NULL DEFAULT '[]',
                industry_ids_json       JSONB NOT NULL DEFAULT '[]',
                market_ids_json         JSONB NOT NULL DEFAULT '[]',
                problem_ids_json        JSONB NOT NULL DEFAULT '[]',
                search_intent_ids_json  JSONB NOT NULL DEFAULT '[]',
                topic_cluster_ids_json  JSONB NOT NULL DEFAULT '[]',
                claim_ids_json          JSONB NOT NULL DEFAULT '[]',
                evidence_ids_json       JSONB NOT NULL DEFAULT '[]',
                source_ids_json         JSONB NOT NULL DEFAULT '[]',
                citation_ids_json       JSONB NOT NULL DEFAULT '[]',
                brand_rule_ids_json     JSONB NOT NULL DEFAULT '[]',
                content_policy_ids_json JSONB NOT NULL DEFAULT '[]',
                snapshot_json           JSONB NOT NULL DEFAULT '{}',
                snapshot_hash           VARCHAR(64) NOT NULL,
                token_estimate          INT,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_grounding_snapshots_uuid ON reach_ai_grounding_snapshots (uuid)");
        $this->db->query("CREATE INDEX idx_ai_grounding_snapshots_request ON reach_ai_grounding_snapshots (generation_request_id)");
        $this->db->query("CREATE INDEX idx_ai_grounding_snapshots_hash ON reach_ai_grounding_snapshots (snapshot_hash)");

        $this->db->query("ALTER TABLE reach_ai_generation_runs
            ADD CONSTRAINT fk_ai_gen_runs_grounding_snapshot
            FOREIGN KEY (grounding_snapshot_id) REFERENCES reach_ai_grounding_snapshots(id)
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_generation_artifacts (
                id                         BIGSERIAL PRIMARY KEY,
                uuid                       UUID NOT NULL DEFAULT gen_random_uuid(),
                generation_request_id      BIGINT NOT NULL REFERENCES reach_ai_generation_requests(id),
                generation_run_id          BIGINT NOT NULL REFERENCES reach_ai_generation_runs(id),
                artifact_type              VARCHAR(64) NOT NULL DEFAULT 'content',
                structured_output_json     JSONB,
                sanitised_output_json      JSONB,
                raw_response_reference     VARCHAR(256),
                schema_validation_status   VARCHAR(32) NOT NULL DEFAULT 'not_run',
                schema_validation_errors   JSONB,
                content_version_id         BIGINT,
                created_at                 TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_generation_artifacts
            ADD CONSTRAINT reach_ai_gen_artifacts_schema_chk
            CHECK (schema_validation_status IN ('not_run','passed','failed','partial'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_gen_artifacts_uuid ON reach_ai_generation_artifacts (uuid)");
        $this->db->query("CREATE INDEX idx_ai_gen_artifacts_request ON reach_ai_generation_artifacts (generation_request_id)");
        $this->db->query("CREATE INDEX idx_ai_gen_artifacts_run ON reach_ai_generation_artifacts (generation_run_id)");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE reach_ai_generation_runs DROP CONSTRAINT IF EXISTS fk_ai_gen_runs_grounding_snapshot");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_generation_artifacts");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_grounding_snapshots");
    }
}
