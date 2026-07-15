<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshPolicyVersions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_policy_versions (
                id                          BIGSERIAL PRIMARY KEY,
                policy_id                   BIGINT NOT NULL REFERENCES reach_refresh_policies(id),
                version_number              INT NOT NULL,
                min_publication_age_days    INT NOT NULL DEFAULT 30,
                comparison_window_days      INT NOT NULL DEFAULT 28,
                position_decline_threshold  NUMERIC(5,2),
                impressions_decline_pct     NUMERIC(5,2),
                clicks_decline_pct          NUMERIC(5,2),
                engagement_decline_pct      NUMERIC(5,2),
                cooldown_days               INT NOT NULL DEFAULT 14,
                required_evidence_sources   JSONB NOT NULL DEFAULT '[]',
                risk_escalation_rules       JSONB NOT NULL DEFAULT '{}',
                approved_by                 BIGINT REFERENCES reach_actors(id),
                approved_at                 TIMESTAMPTZ,
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (policy_id, version_number)
            )
        ");
        $this->db->query("CREATE INDEX reach_refresh_policy_versions_policy ON reach_refresh_policy_versions (policy_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_policy_versions CASCADE");
    }
}
