<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshEvidenceSnapshots extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_evidence_snapshots (
                id                   BIGSERIAL PRIMARY KEY,
                uuid                 UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id            BIGINT NOT NULL REFERENCES reach_actors(id),
                content_identity_id  BIGINT NOT NULL REFERENCES reach_content_identities(id),
                policy_version_id    BIGINT NOT NULL REFERENCES reach_refresh_policy_versions(id),
                evidence_date        DATE NOT NULL,
                window_days          INT NOT NULL,
                evidence_packet      JSONB NOT NULL,
                completeness_score   NUMERIC(4,3) NOT NULL,
                missing_domains      JSONB NOT NULL DEFAULT '[]',
                freshness_state      JSONB NOT NULL DEFAULT '{}',
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (content_identity_id, policy_version_id, evidence_date)
            )
        ");
        $this->db->query("CREATE UNIQUE INDEX reach_refresh_evidence_snapshots_uuid ON reach_refresh_evidence_snapshots (uuid)");
        $this->db->query("CREATE INDEX reach_refresh_evidence_snapshots_tenant ON reach_refresh_evidence_snapshots (tenant_id, evidence_date)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_evidence_snapshots CASCADE");
    }
}
