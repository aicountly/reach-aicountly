<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachReadinessAuditRuns extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_readiness_audit_runs (
                id           BIGSERIAL PRIMARY KEY,
                tenant_id    BIGINT NOT NULL REFERENCES reach_actors(id),
                audit_type   VARCHAR(50) NOT NULL
                    CHECK (audit_type IN ('security','privacy','ai_governance','migration','performance','operational','dr')),
                status       VARCHAR(20) NOT NULL DEFAULT 'running'
                    CHECK (status IN ('running','completed','failed')),
                started_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                completed_at TIMESTAMPTZ,
                triggered_by BIGINT REFERENCES reach_actors(id)
            )
        ");
        $this->db->query("CREATE INDEX reach_readiness_audit_runs_tenant ON reach_readiness_audit_runs (tenant_id, audit_type)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_readiness_audit_runs CASCADE");
    }
}
