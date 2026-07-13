<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachKbPublicationProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_kb_publication_profiles (
                id                              BIGSERIAL PRIMARY KEY,
                content_item_id                 BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                article_type                    VARCHAR(32) NOT NULL DEFAULT 'concept'
                                                CHECK (article_type IN ('concept','how_to','troubleshooting','faq','release_guide','configuration','integration','reference','best_practice')),
                product_id                      BIGINT REFERENCES reach_products(id) ON DELETE SET NULL,
                module_id                       BIGINT REFERENCES reach_modules(id) ON DELETE SET NULL,
                feature_id                      BIGINT REFERENCES reach_features(id) ON DELETE SET NULL,
                applicable_versions_json        JSONB NOT NULL DEFAULT '{}'::jsonb,
                prerequisites_json              JSONB NOT NULL DEFAULT '[]'::jsonb,
                steps_json                      JSONB NOT NULL DEFAULT '[]'::jsonb,
                troubleshooting_json            JSONB NOT NULL DEFAULT '[]'::jsonb,
                related_articles_json           JSONB NOT NULL DEFAULT '[]'::jsonb,
                support_escalation_json         JSONB NOT NULL DEFAULT '{}'::jsonb,
                feedback_enabled                BOOLEAN NOT NULL DEFAULT TRUE,
                difficulty_level                VARCHAR(32) CHECK (difficulty_level IN ('beginner','intermediate','advanced','expert')),
                estimated_completion_minutes    SMALLINT,
                refresh_status                  VARCHAR(32) NOT NULL DEFAULT 'published'
                                                CHECK (refresh_status IN ('published','review_due','refresh_due','refresh_in_progress','reapproval_required','republish_ready','superseded','archived')),
                refresh_due_at                  TIMESTAMPTZ,
                last_refreshed_at               TIMESTAMPTZ,
                created_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (content_item_id)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_kb_pub_profiles_item ON reach_kb_publication_profiles(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_kb_pub_profiles_type ON reach_kb_publication_profiles(article_type)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_kb_pub_product ON reach_kb_publication_profiles(product_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_kb_publication_profiles CASCADE');
    }
}
