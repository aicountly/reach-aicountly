<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityAnswerVersions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_answer_versions (
                id                      BIGSERIAL PRIMARY KEY,
                answer_id               BIGINT NOT NULL REFERENCES reach_community_official_answers(id) ON DELETE RESTRICT,
                version_number          INT NOT NULL,
                content                 TEXT NOT NULL DEFAULT '',
                excerpt                 TEXT NOT NULL DEFAULT '',
                sources                 JSONB NOT NULL DEFAULT '[]'::jsonb,
                grounding_snapshot_id   BIGINT,
                generation_request_id   BIGINT,
                generation_run_id       BIGINT,
                generation_artifact_id  BIGINT,
                prompt_version          VARCHAR(80),
                model_route             VARCHAR(120),
                validation_results      JSONB NOT NULL DEFAULT '{}'::jsonb,
                risk_findings           JSONB NOT NULL DEFAULT '[]'::jsonb,
                moderation_decision     VARCHAR(20) CHECK (moderation_decision IN (
                                            'pending','clean','flagged','blocked','overridden'
                                        )),
                reviewer_id             BIGINT REFERENCES reach_users(id) ON DELETE SET NULL,
                approver_id             BIGINT REFERENCES reach_users(id) ON DELETE SET NULL,
                approval_timestamp      TIMESTAMPTZ,
                checksum                VARCHAR(64) NOT NULL,
                creation_reason         VARCHAR(40) NOT NULL DEFAULT 'initial'
                                            CHECK (creation_reason IN (
                                                'initial','edit','correction','translation'
                                            )),
                superseded_by           INT,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (answer_id, version_number)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcav_answer ON reach_community_answer_versions(answer_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcav_checksum ON reach_community_answer_versions(checksum)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_answer_versions CASCADE');
    }
}
