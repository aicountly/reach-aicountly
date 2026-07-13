<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityAnswerApprovals extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_answer_approvals (
                id                          BIGSERIAL PRIMARY KEY,
                answer_id                   BIGINT NOT NULL REFERENCES reach_community_official_answers(id) ON DELETE RESTRICT,
                answer_version_number       INT NOT NULL,
                version_checksum            VARCHAR(64) NOT NULL,
                reach_approval_id           BIGINT REFERENCES reach_approvals(id) ON DELETE SET NULL,
                approved_by                 BIGINT NOT NULL REFERENCES reach_users(id) ON DELETE RESTRICT,
                approval_type               VARCHAR(30) NOT NULL DEFAULT 'standard'
                                                CHECK (approval_type IN (
                                                    'standard','professional_review','compliance_review'
                                                )),
                outcome                     VARCHAR(20) NOT NULL DEFAULT 'approved'
                                                CHECK (outcome IN ('approved','rejected','changes_requested')),
                reason                      TEXT,
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcaa_answer ON reach_community_answer_approvals(answer_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcaa_approved_by ON reach_community_answer_approvals(approved_by)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_answer_approvals CASCADE');
    }
}
