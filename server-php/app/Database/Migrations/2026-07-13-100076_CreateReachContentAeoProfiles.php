<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentAeoProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_aeo_profiles (
                id                      BIGSERIAL PRIMARY KEY,
                content_item_id         BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                content_version_id      BIGINT REFERENCES reach_content_versions(id) ON DELETE SET NULL,
                answer_summary          TEXT,
                concise_answer          TEXT,
                questions_answered_json JSONB  NOT NULL DEFAULT '[]'::jsonb,
                faq_candidates_json     JSONB  NOT NULL DEFAULT '[]'::jsonb,
                definition_blocks_json  JSONB  NOT NULL DEFAULT '[]'::jsonb,
                step_blocks_json        JSONB  NOT NULL DEFAULT '[]'::jsonb,
                entity_mentions_json    JSONB  NOT NULL DEFAULT '[]'::jsonb,
                citation_summary_json   JSONB  NOT NULL DEFAULT '[]'::jsonb,
                source_summary_json     JSONB  NOT NULL DEFAULT '[]'::jsonb,
                answerability_score     SMALLINT CHECK (answerability_score BETWEEN 0 AND 100),
                citation_score          SMALLINT CHECK (citation_score BETWEEN 0 AND 100),
                entity_clarity_score    SMALLINT CHECK (entity_clarity_score BETWEEN 0 AND 100),
                aeo_status              VARCHAR(32) NOT NULL DEFAULT 'draft'
                                        CHECK (aeo_status IN ('draft','incomplete','warning','ready','blocked','not_applicable','superseded')),
                findings_json           JSONB  NOT NULL DEFAULT '[]'::jsonb,
                reviewed_at             TIMESTAMPTZ,
                reviewed_by             BIGINT REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (content_item_id)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_aeo_profiles_item ON reach_content_aeo_profiles(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_aeo_profiles_status ON reach_content_aeo_profiles(aeo_status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_content_aeo_profiles CASCADE');
    }
}
