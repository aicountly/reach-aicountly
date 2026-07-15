<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshOutcomeMetrics extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_outcome_metrics (
                id                   BIGSERIAL PRIMARY KEY,
                outcome_window_id    BIGINT NOT NULL REFERENCES reach_refresh_outcome_windows(id),
                metric_domain        VARCHAR(30) NOT NULL
                    CHECK (metric_domain IN ('search','engagement','conversion','visibility','indexing')),
                metric_name          VARCHAR(80) NOT NULL,
                baseline_value       NUMERIC(14,4),
                post_value           NUMERIC(14,4),
                observed_change_pct  NUMERIC(8,4),
                evidence_source      VARCHAR(50),
                confidence           VARCHAR(20)
                    CHECK (confidence IN ('low','medium','high','insufficient_data')),
                data_points_baseline INT,
                data_points_post     INT,
                measured_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX reach_refresh_outcome_metrics_window ON reach_refresh_outcome_metrics (outcome_window_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_outcome_metrics CASCADE");
    }
}
