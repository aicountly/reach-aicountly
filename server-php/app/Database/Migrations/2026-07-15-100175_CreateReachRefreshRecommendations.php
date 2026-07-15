<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshRecommendations extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_recommendations (
                id                   BIGSERIAL PRIMARY KEY,
                uuid                 UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id            BIGINT NOT NULL REFERENCES reach_actors(id),
                content_identity_id  BIGINT NOT NULL REFERENCES reach_content_identities(id),
                policy_version_id    BIGINT NOT NULL REFERENCES reach_refresh_policy_versions(id),
                evidence_snapshot_id BIGINT NOT NULL REFERENCES reach_refresh_evidence_snapshots(id),
                status               VARCHAR(30) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','recommended','triaged','accepted','rejected','deferred','superseded','expired')),
                risk_classification  VARCHAR(20) NOT NULL DEFAULT 'low'
                    CHECK (risk_classification IN ('low','medium','high','critical')),
                confidence           NUMERIC(4,3),
                effort_estimate      VARCHAR(20) CHECK (effort_estimate IN ('low','medium','high')),
                cooldown_until       TIMESTAMPTZ,
                superseded_by        BIGINT REFERENCES reach_refresh_recommendations(id),
                assigned_to          BIGINT REFERENCES reach_actors(id),
                due_date             DATE,
                triage_notes         TEXT,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE UNIQUE INDEX reach_refresh_recommendations_uuid ON reach_refresh_recommendations (uuid)");
        $this->db->query("CREATE INDEX reach_refresh_recommendations_tenant_status ON reach_refresh_recommendations (tenant_id, status)");
        $this->db->query("CREATE INDEX reach_refresh_recommendations_content ON reach_refresh_recommendations (content_identity_id, status)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_recommendations CASCADE");
    }
}
