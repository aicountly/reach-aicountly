<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachReadinessFindings extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_readiness_findings (
                id                  BIGSERIAL PRIMARY KEY,
                audit_run_id        BIGINT NOT NULL REFERENCES reach_readiness_audit_runs(id),
                severity            VARCHAR(20) NOT NULL
                    CHECK (severity IN ('critical','high','medium','low','info')),
                category            VARCHAR(50) NOT NULL,
                title               VARCHAR(200) NOT NULL,
                description         TEXT NOT NULL,
                affected_component  VARCHAR(100),
                resolution_status   VARCHAR(20) NOT NULL DEFAULT 'open'
                    CHECK (resolution_status IN ('open','in_progress','resolved','accepted_risk','deferred')),
                accepted_risk_reason TEXT,
                accepted_by         BIGINT REFERENCES reach_actors(id),
                accepted_at         TIMESTAMPTZ,
                resolved_at         TIMESTAMPTZ,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX reach_readiness_findings_run ON reach_readiness_findings (audit_run_id, severity)");
        $this->db->query("CREATE INDEX reach_readiness_findings_status ON reach_readiness_findings (resolution_status, severity)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_readiness_findings CASCADE");
    }
}
