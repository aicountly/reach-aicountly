<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityQuestions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_questions (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                content_item_id         BIGINT REFERENCES reach_content_items(id) ON DELETE SET NULL,
                space_id                BIGINT REFERENCES reach_community_spaces(id) ON DELETE SET NULL,
                source_type             VARCHAR(40) NOT NULL DEFAULT 'manual'
                                            CHECK (source_type IN (
                                                'manual','import','content_request',
                                                'official_question','public_submission'
                                            )),
                source_url              TEXT,
                external_question_id    VARCHAR(255),
                author_reference        VARCHAR(512),
                author_display_consent  BOOLEAN NOT NULL DEFAULT FALSE,
                title                   VARCHAR(512) NOT NULL,
                body                    TEXT NOT NULL DEFAULT '',
                language                VARCHAR(10) NOT NULL DEFAULT 'en',
                product                 VARCHAR(120),
                category                VARCHAR(120),
                tags                    TEXT[] NOT NULL DEFAULT '{}',
                jurisdiction            VARCHAR(80),
                question_timestamp      TIMESTAMPTZ,
                intake_timestamp        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                sensitivity_flags       TEXT[] NOT NULL DEFAULT '{}',
                personal_data_detected  BOOLEAN NOT NULL DEFAULT FALSE,
                spam_score              DECIMAL(4,3) NOT NULL DEFAULT 0.000,
                moderation_state        VARCHAR(30) NOT NULL DEFAULT 'clean'
                                            CHECK (moderation_state IN (
                                                'clean','pending_review','flagged','removed'
                                            )),
                duplicate_cluster_id    BIGINT,
                triage_score            DECIMAL(6,3) NOT NULL DEFAULT 0.000,
                assigned_to             BIGINT REFERENCES reach_users(id) ON DELETE SET NULL,
                status                  VARCHAR(30) NOT NULL DEFAULT 'intake'
                                            CHECK (status IN (
                                                'intake','triaged','draft_requested',
                                                'generating','draft_generated','validation_failed',
                                                'moderation_required','editorial_review',
                                                'professional_review','changes_requested',
                                                'approved','scheduled','publishing','published',
                                                'verification_failed','correction_required',
                                                'unpublishing','unpublished','restoring',
                                                'withdrawn','archived','duplicate_merged'
                                            )),
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcq_uuid ON reach_community_questions(uuid)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcq_space ON reach_community_questions(space_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcq_status ON reach_community_questions(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcq_source_type ON reach_community_questions(source_type)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcq_assigned ON reach_community_questions(assigned_to)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcq_intake ON reach_community_questions(intake_timestamp)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_questions CASCADE');
    }
}
