<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityModerationFindings extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_moderation_findings (
                id                  BIGSERIAL PRIMARY KEY,
                answer_version_id   BIGINT REFERENCES reach_community_answer_versions(id) ON DELETE CASCADE,
                question_id         BIGINT REFERENCES reach_community_questions(id) ON DELETE CASCADE,
                finding_type        VARCHAR(40) NOT NULL
                                        CHECK (finding_type IN (
                                            'spam','abuse','harassment','profanity',
                                            'personal_data','confidential_information',
                                            'legal_risk','tax_risk','unsupported_claims',
                                            'hallucinated_features','outdated_product_behaviour',
                                            'duplicate_question','duplicate_answer',
                                            'promotional_manipulation','impersonation_risk',
                                            'unsafe_links','malicious_html','prompt_injection',
                                            'prohibited_content'
                                        )),
                severity            VARCHAR(20) NOT NULL DEFAULT 'warning'
                                        CHECK (severity IN ('info','warning','error','critical')),
                details             JSONB NOT NULL DEFAULT '{}'::jsonb,
                auto_action         VARCHAR(30),
                override_by         BIGINT REFERENCES reach_users(id) ON DELETE SET NULL,
                override_reason     TEXT,
                override_at         TIMESTAMPTZ,
                status              VARCHAR(20) NOT NULL DEFAULT 'open'
                                        CHECK (status IN ('open','resolved','overridden','dismissed')),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcmf_answer_version ON reach_community_moderation_findings(answer_version_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcmf_question ON reach_community_moderation_findings(question_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcmf_type ON reach_community_moderation_findings(finding_type)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcmf_status ON reach_community_moderation_findings(status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_moderation_findings CASCADE');
    }
}
