<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentSeoProfiles extends Migration
{
    public function up(): void
    {
        // Defensive guard: ensure reach_actors exists before adding the FK.
        // The canonical creator is 2026-07-12-100065_CreateReachActors, which
        // sorts before all 2026-07-13 migrations.  However, if CI4's singleton
        // MigrationRunner skips that migration because its version string is
        // lexicographically smaller than the recorded last-applied version, this
        // guard creates the table so the FK reference below never fails.
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_actors (
                id            BIGSERIAL    PRIMARY KEY,
                uuid          UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                actor_type    VARCHAR(16)  NOT NULL DEFAULT 'human'
                              CHECK (actor_type IN ('human','system','bot','service')),
                display_name  VARCHAR(255),
                email         VARCHAR(320),
                is_active     BOOLEAN      NOT NULL DEFAULT true,
                created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");

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
        // Defensive cleanup: drop reach_actors only if the canonical migration
        // (2026-07-12-100065_CreateReachActors) was never recorded in history,
        // meaning this migration created it as a fallback.  The IF EXISTS guard
        // is a no-op when 100065.down() already removed it in normal rollback.
        $row = $this->db->query(
            "SELECT 1 FROM migrations WHERE version LIKE '%100065%' AND class LIKE '%CreateReachActors%' LIMIT 1"
        )->getRowArray();
        if (! $row) {
            $this->db->query('DROP TABLE IF EXISTS reach_actors CASCADE');
        }
    }
}
