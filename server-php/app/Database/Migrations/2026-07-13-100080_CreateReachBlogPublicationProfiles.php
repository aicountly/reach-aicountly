<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachBlogPublicationProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_blog_publication_profiles (
                id                          BIGSERIAL PRIMARY KEY,
                content_item_id             BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                category                    VARCHAR(100),
                tags_json                   JSONB NOT NULL DEFAULT '[]'::jsonb,
                author_reference            VARCHAR(255),
                reviewer_reference          VARCHAR(255),
                featured_image_reference    VARCHAR(512),
                featured_image_alt          VARCHAR(512),
                excerpt                     TEXT,
                reading_time_minutes        SMALLINT,
                publication_template        VARCHAR(64),
                comments_enabled            BOOLEAN NOT NULL DEFAULT FALSE,
                related_content_json        JSONB NOT NULL DEFAULT '[]'::jsonb,
                cta_configuration_json      JSONB NOT NULL DEFAULT '{}'::jsonb,
                refresh_status              VARCHAR(32) NOT NULL DEFAULT 'published'
                                            CHECK (refresh_status IN ('published','review_due','refresh_due','refresh_in_progress','reapproval_required','republish_ready','superseded','archived')),
                refresh_due_at              TIMESTAMPTZ,
                last_refreshed_at           TIMESTAMPTZ,
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (content_item_id)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_pub_profiles_item ON reach_blog_publication_profiles(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_pub_refresh ON reach_blog_publication_profiles(refresh_status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_blog_publication_profiles CASCADE');
    }
}
