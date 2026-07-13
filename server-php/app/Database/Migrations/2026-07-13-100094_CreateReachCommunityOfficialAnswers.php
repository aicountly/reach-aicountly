<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityOfficialAnswers extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_official_answers (
                id                          BIGSERIAL PRIMARY KEY,
                uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                question_id                 BIGINT NOT NULL REFERENCES reach_community_questions(id) ON DELETE RESTRICT,
                identity_id                 BIGINT NOT NULL REFERENCES reach_community_official_identities(id) ON DELETE RESTRICT,
                current_version             INT NOT NULL DEFAULT 1,
                approved_version            INT,
                approved_version_checksum   VARCHAR(64),
                public_external_id          VARCHAR(255),
                public_url                  TEXT,
                publication_status          VARCHAR(30) NOT NULL DEFAULT 'unpublished'
                                                CHECK (publication_status IN (
                                                    'unpublished','scheduled','published','withdrawn'
                                                )),
                ai_assisted                 BOOLEAN NOT NULL DEFAULT FALSE,
                human_reviewed              BOOLEAN NOT NULL DEFAULT FALSE,
                risk_classification         VARCHAR(20) NOT NULL DEFAULT 'low'
                                                CHECK (risk_classification IN ('low','medium','high','critical')),
                jurisdiction                VARCHAR(80),
                product                     VARCHAR(120),
                language                    VARCHAR(10) NOT NULL DEFAULT 'en',
                correction_state            VARCHAR(20) NOT NULL DEFAULT 'none'
                                                CHECK (correction_state IN ('none','pending','corrected')),
                correction_note             TEXT,
                withdrawal_state            VARCHAR(20) NOT NULL DEFAULT 'none'
                                                CHECK (withdrawal_state IN ('none','withdrawn')),
                status                      VARCHAR(30) NOT NULL DEFAULT 'intake'
                                                CHECK (status IN (
                                                    'intake','triaged','draft_requested',
                                                    'generating','draft_generated','validation_failed',
                                                    'moderation_required','editorial_review',
                                                    'professional_review','changes_requested',
                                                    'approved','scheduled','publishing','published',
                                                    'verification_failed','correction_required',
                                                    'unpublishing','unpublished','restoring',
                                                    'withdrawn','archived'
                                                )),
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_uuid ON reach_community_official_answers(uuid)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_question ON reach_community_official_answers(question_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_status ON reach_community_official_answers(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_pub_status ON reach_community_official_answers(publication_status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_official_answers CASCADE');
    }
}
