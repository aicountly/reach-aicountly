<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCompetitorObservationAggregates extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_competitor_observation_aggregates (
                id                  BIGSERIAL PRIMARY KEY,
                competitor_id       BIGINT NOT NULL REFERENCES reach_competitors(id) ON DELETE CASCADE,
                prompt_id           BIGINT NOT NULL REFERENCES reach_ai_visibility_prompts(id) ON DELETE CASCADE,
                tenant_id           BIGINT NOT NULL,
                period_start        DATE NOT NULL,
                period_end          DATE NOT NULL,
                total_runs          INT NOT NULL DEFAULT 0,
                mention_count       INT NOT NULL DEFAULT 0,
                citation_count      INT NOT NULL DEFAULT 0,
                mention_rate        NUMERIC(8,6),
                avg_mention_order   NUMERIC(6,2),
                sample_scope_note   TEXT NOT NULL DEFAULT 'Sample of AI responses in monitored period. Does not represent comprehensive market data.',
                computed_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_competitor_obs_agg UNIQUE (competitor_id, prompt_id, period_start, period_end)
            )
        ");
        $this->db->query("CREATE INDEX idx_competitor_obs_agg_competitor ON reach_competitor_observation_aggregates (competitor_id, period_start DESC)");
        $this->db->query("CREATE INDEX idx_competitor_obs_agg_tenant ON reach_competitor_observation_aggregates (tenant_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_competitor_observation_aggregates CASCADE");
    }
}
