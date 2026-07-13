<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentSeoProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_seo_profiles (
                id                      BIGSERIAL PRIMARY KEY,
                content_item_id         BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                content_version_id      BIGINT REFERENCES reach_content_versions(id) ON DELETE SET NULL,
                primary_keyword         VARCHAR(255),
                secondary_keywords_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                search_intent_id        BIGINT REFERENCES reach_search_intents(id) ON DELETE SET NULL,
                meta_title              VARCHAR(200),
                meta_description        VARCHAR(320),
                slug                    VARCHAR(300),
                canonical_preference    VARCHAR(32)  NOT NULL DEFAULT 'self_canonical'
                                        CHECK (canonical_preference IN ('self_canonical','canonical_to_existing','noindex','redirect_to_existing','historical_archive')),
                robots_directive        VARCHAR(64)  NOT NULL DEFAULT 'index,follow',
                focus_region            VARCHAR(8),
                focus_language          VARCHAR(8)   NOT NULL DEFAULT 'en',
                title_score             SMALLINT     CHECK (title_score BETWEEN 0 AND 100),
                description_score       SMALLINT     CHECK (description_score BETWEEN 0 AND 100),
                heading_score           SMALLINT     CHECK (heading_score BETWEEN 0 AND 100),
                keyword_score           SMALLINT     CHECK (keyword_score BETWEEN 0 AND 100),
                internal_link_score     SMALLINT     CHECK (internal_link_score BETWEEN 0 AND 100),
                readability_score       SMALLINT     CHECK (readability_score BETWEEN 0 AND 100),
                duplicate_risk_score    SMALLINT     CHECK (duplicate_risk_score BETWEEN 0 AND 100),
                seo_status              VARCHAR(32)  NOT NULL DEFAULT 'draft'
                                        CHECK (seo_status IN ('draft','incomplete','warning','ready','blocked','superseded')),
                findings_json           JSONB        NOT NULL DEFAULT '[]'::jsonb,
                reviewed_at             TIMESTAMPTZ,
                reviewed_by             BIGINT       REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                UNIQUE (content_item_id)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_seo_profiles_item ON reach_content_seo_profiles(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_seo_profiles_status ON reach_content_seo_profiles(seo_status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_seo_profiles_slug ON reach_content_seo_profiles(slug)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_content_seo_profiles CASCADE');
    }
}
