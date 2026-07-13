<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityQuestionClassifications extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_question_classifications (
                id                          BIGSERIAL PRIMARY KEY,
                question_id                 BIGINT NOT NULL REFERENCES reach_community_questions(id) ON DELETE CASCADE,
                product_classification      VARCHAR(120),
                category_classification     VARCHAR(120),
                risk_classification         VARCHAR(20) NOT NULL DEFAULT 'low'
                                                CHECK (risk_classification IN ('low','medium','high','critical')),
                jurisdiction_classification VARCHAR(80),
                language_detected           VARCHAR(10),
                complexity_score            DECIMAL(4,3) NOT NULL DEFAULT 0.000,
                classified_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                classified_by               VARCHAR(40) NOT NULL DEFAULT 'ai'
                                                CHECK (classified_by IN ('ai','human')),
                model_slug                  VARCHAR(120)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcqc_question ON reach_community_question_classifications(question_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcqc_risk ON reach_community_question_classifications(risk_classification)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_question_classifications CASCADE');
    }
}
