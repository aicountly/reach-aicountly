<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityAnswerVerifications extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_answer_verifications (
                id                      BIGSERIAL PRIMARY KEY,
                deployment_id           BIGINT REFERENCES reach_community_deployments(id) ON DELETE SET NULL,
                answer_id               BIGINT NOT NULL REFERENCES reach_community_official_answers(id) ON DELETE RESTRICT,
                verified_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                public_status           VARCHAR(30),
                public_version          INT,
                checksum_match          BOOLEAN,
                expected_checksum       VARCHAR(64),
                actual_checksum         VARCHAR(64),
                canonical_url_ok        BOOLEAN,
                robots_ok               BOOLEAN,
                sitemap_ok              BOOLEAN,
                verification_outcome    VARCHAR(20) NOT NULL DEFAULT 'pending'
                                            CHECK (verification_outcome IN (
                                                'pending','passed','failed','mismatch','not_found'
                                            )),
                details                 JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcav_ver_answer ON reach_community_answer_verifications(answer_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcav_ver_outcome ON reach_community_answer_verifications(verification_outcome)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_answer_verifications CASCADE');
    }
}
