<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityAnalyticsCache extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_analytics_cache (
                id              BIGSERIAL PRIMARY KEY,
                metric_key      VARCHAR(120) NOT NULL,
                dimension       VARCHAR(120),
                period_start    TIMESTAMPTZ,
                period_end      TIMESTAMPTZ,
                value           DECIMAL(15,4) NOT NULL DEFAULT 0,
                meta            JSONB NOT NULL DEFAULT '{}'::jsonb,
                computed_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (metric_key, dimension, period_start, period_end)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcac_key ON reach_community_analytics_cache(metric_key)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcac_computed ON reach_community_analytics_cache(computed_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_analytics_cache CASCADE');
    }
}
