<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityDeployments extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_deployments (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                answer_id               BIGINT NOT NULL REFERENCES reach_community_official_answers(id) ON DELETE RESTRICT,
                answer_version_number   INT NOT NULL,
                version_checksum        VARCHAR(64) NOT NULL,
                operation               VARCHAR(30) NOT NULL
                                            CHECK (operation IN (
                                                'publish','unpublish','withdraw','restore','update'
                                            )),
                idempotency_key         UUID NOT NULL UNIQUE,
                status                  VARCHAR(20) NOT NULL DEFAULT 'pending'
                                            CHECK (status IN (
                                                'pending','executing','succeeded','failed','retrying','cancelled'
                                            )),
                attempt_count           INT NOT NULL DEFAULT 0,
                max_attempts            INT NOT NULL DEFAULT 3,
                last_error              TEXT,
                last_error_category     VARCHAR(40),
                next_retry_at           TIMESTAMPTZ,
                public_answer_id        VARCHAR(255),
                public_url              TEXT,
                response_checksum       VARCHAR(64),
                deployed_at             TIMESTAMPTZ,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcd_answer ON reach_community_deployments(answer_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcd_status ON reach_community_deployments(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcd_retry ON reach_community_deployments(next_retry_at) WHERE next_retry_at IS NOT NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_deployments CASCADE');
    }
}
