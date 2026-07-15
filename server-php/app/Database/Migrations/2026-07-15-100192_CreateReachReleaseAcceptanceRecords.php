<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachReleaseAcceptanceRecords extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_release_acceptance_records (
                id                   BIGSERIAL PRIMARY KEY,
                release_name         VARCHAR(100) NOT NULL,
                recommendation       VARCHAR(50) NOT NULL
                    CHECK (recommendation IN ('ready_controlled','ready_with_limitations','not_ready')),
                evidence_summary     TEXT NOT NULL,
                blockers_resolved    BOOLEAN NOT NULL,
                limitations_accepted JSONB NOT NULL DEFAULT '[]',
                accepted_risks       JSONB NOT NULL DEFAULT '[]',
                prerequisite_checks  JSONB NOT NULL,
                accepted_by          BIGINT NOT NULL REFERENCES reach_actors(id),
                accepted_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_release_acceptance_records CASCADE");
    }
}
