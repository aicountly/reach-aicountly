<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshWorkflows extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_workflows (
                id                   BIGSERIAL PRIMARY KEY,
                uuid                 UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id            BIGINT NOT NULL REFERENCES reach_actors(id),
                recommendation_id    BIGINT NOT NULL REFERENCES reach_refresh_recommendations(id),
                content_identity_id  BIGINT NOT NULL REFERENCES reach_content_identities(id),
                status               VARCHAR(40) NOT NULL DEFAULT 'accepted'
                    CHECK (status IN (
                        'accepted','brief_prepared','draft_generating','draft_ready','in_review',
                        'approved','publish_queued','published','monitoring','outcome_recorded',
                        'rejected','deferred','changes_requested','blocked','cancelled','superseded','failed','withdrawn'
                    )),
                lock_version         INT NOT NULL DEFAULT 0,
                refresh_objective    TEXT,
                risk_classification  VARCHAR(20),
                assigned_to          BIGINT REFERENCES reach_actors(id),
                due_date             DATE,
                approved_by          BIGINT REFERENCES reach_actors(id),
                approved_at          TIMESTAMPTZ,
                cancelled_by         BIGINT REFERENCES reach_actors(id),
                cancelled_at         TIMESTAMPTZ,
                cancel_reason        TEXT,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE UNIQUE INDEX reach_refresh_workflows_uuid ON reach_refresh_workflows (uuid)");
        $this->db->query("CREATE INDEX reach_refresh_workflows_tenant_status ON reach_refresh_workflows (tenant_id, status)");
        $this->db->query("CREATE INDEX reach_refresh_workflows_content ON reach_refresh_workflows (content_identity_id, status)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_workflows CASCADE");
    }
}
