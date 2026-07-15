<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshBriefs extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_briefs (
                id                   BIGSERIAL PRIMARY KEY,
                workflow_id          BIGINT NOT NULL REFERENCES reach_refresh_workflows(id),
                evidence_snapshot_id BIGINT NOT NULL REFERENCES reach_refresh_evidence_snapshots(id),
                refresh_objective    TEXT NOT NULL,
                key_changes          JSONB NOT NULL DEFAULT '[]',
                target_sections      JSONB NOT NULL DEFAULT '[]',
                source_requirements  JSONB NOT NULL DEFAULT '[]',
                created_by           BIGINT NOT NULL REFERENCES reach_actors(id),
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (workflow_id)
            )
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_briefs CASCADE");
    }
}
