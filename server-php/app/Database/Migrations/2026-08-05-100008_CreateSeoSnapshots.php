<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SEO Command Centre storage:
 * - reach_seo_rank_snapshots: daily GSC-derived position/click history per
 *   tracked keyword (reach_keyword_ideas). No SERP scraping — positions come
 *   from ingested Search Console facts.
 * - reach_backlink_snapshots: daily backlink-health snapshot from whichever
 *   provider is configured (free internal-signals provider by default;
 *   DataForSEO/Ahrefs adapters can be added via env without schema change).
 */
class CreateSeoSnapshots extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_seo_rank_snapshots (
                id             BIGSERIAL PRIMARY KEY,
                keyword_id     BIGINT NOT NULL REFERENCES reach_keyword_ideas(id) ON DELETE CASCADE,
                keyword        VARCHAR(300) NOT NULL,
                metric_date    DATE NOT NULL,
                clicks         INT NOT NULL DEFAULT 0,
                impressions    INT NOT NULL DEFAULT 0,
                ctr            NUMERIC(8,6),
                avg_position   NUMERIC(8,2),
                best_page_url  TEXT,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (keyword_id, metric_date)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_seo_rank_snapshots_date ON reach_seo_rank_snapshots(metric_date DESC)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_backlink_snapshots (
                id                BIGSERIAL PRIMARY KEY,
                provider          VARCHAR(40) NOT NULL,
                snapshot_date     DATE NOT NULL,
                total_backlinks   INT,
                referring_domains INT,
                internal_links    INT NOT NULL DEFAULT 0,
                published_urls    INT NOT NULL DEFAULT 0,
                details_json      JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (provider, snapshot_date)
            )
        ");
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_seo_rank_snapshots', true);
        $this->forge->dropTable('reach_backlink_snapshots', true);
    }
}
