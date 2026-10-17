<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SEO Tracker: real Google SERP positions for a curated keyword list, for
 * aicountly.com and each tracked competitor (reach_competitors.website_domain).
 *
 * - reach_seo_tracked_keywords: what we pay to check. Deliberately separate
 *   from reach_keyword_ideas (content planning) — every enabled row here
 *   costs a DataForSEO call per day, so it is a short, curated list.
 * - reach_seo_serp_checks: one row per keyword per day — our best rank/URL
 *   (NULL = not in the top `depth` results), the provider cost, or the error.
 * - reach_seo_serp_rankings: best rank per domain (ours + each competitor that
 *   appeared) for that check. A competitor with no row was not in the top
 *   `depth` results.
 *
 * Rows are only ever written from a real provider response; with no
 * credentials the tracker reports `not_configured` and writes nothing.
 */
class CreateReachSeoSerpTracker extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_seo_tracked_keywords (
                id               BIGSERIAL PRIMARY KEY,
                keyword          VARCHAR(300) NOT NULL,
                product          VARCHAR(40),
                priority         VARCHAR(16) NOT NULL DEFAULT 'medium'
                                 CHECK (priority IN ('low','medium','high')),
                enabled          BOOLEAN NOT NULL DEFAULT TRUE,
                notes            TEXT,
                last_checked_at  TIMESTAMPTZ,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_seo_tracked_keyword ON reach_seo_tracked_keywords (LOWER(keyword))');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_seo_serp_checks (
                id                BIGSERIAL PRIMARY KEY,
                keyword_id        BIGINT NOT NULL REFERENCES reach_seo_tracked_keywords(id) ON DELETE CASCADE,
                check_date        DATE NOT NULL,
                status            VARCHAR(16) NOT NULL CHECK (status IN ('ok','failed')),
                provider          VARCHAR(30) NOT NULL DEFAULT 'dataforseo',
                location_code     INT,
                language_code     VARCHAR(10),
                depth             INT,
                our_rank          INT,
                our_url           TEXT,
                items_count       INT,
                se_results_count  BIGINT,
                cost              NUMERIC(10,5),
                error             TEXT,
                checked_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_seo_serp_check UNIQUE (keyword_id, check_date)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_seo_serp_checks_date ON reach_seo_serp_checks (check_date DESC)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_seo_serp_rankings (
                id             BIGSERIAL PRIMARY KEY,
                check_id       BIGINT NOT NULL REFERENCES reach_seo_serp_checks(id) ON DELETE CASCADE,
                keyword_id     BIGINT NOT NULL REFERENCES reach_seo_tracked_keywords(id) ON DELETE CASCADE,
                check_date     DATE NOT NULL,
                competitor_id  BIGINT REFERENCES reach_competitors(id) ON DELETE CASCADE,
                is_ours        BOOLEAN NOT NULL DEFAULT FALSE,
                domain         VARCHAR(190) NOT NULL,
                rank_absolute  INT NOT NULL,
                url            TEXT,
                CONSTRAINT uq_seo_serp_ranking UNIQUE (keyword_id, check_date, domain)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_seo_serp_rankings_date ON reach_seo_serp_rankings (check_date DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_seo_serp_rankings_competitor ON reach_seo_serp_rankings (competitor_id, check_date DESC)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_seo_serp_rankings');
        $this->db->query('DROP TABLE IF EXISTS reach_seo_serp_checks');
        $this->db->query('DROP TABLE IF EXISTS reach_seo_tracked_keywords');
    }
}
