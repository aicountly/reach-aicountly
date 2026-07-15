<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachDisasterRecoveryTests extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_disaster_recovery_tests (
                id                 BIGSERIAL PRIMARY KEY,
                test_type          VARCHAR(50) NOT NULL
                    CHECK (test_type IN ('backup_verify','restore_verify','rollback_verify','migration_verify')),
                environment        VARCHAR(30) NOT NULL
                    CHECK (environment IN ('local','staging')),
                status             VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','passed','failed','skipped')),
                rpo_minutes        INT,
                rto_minutes        INT,
                procedure_followed TEXT,
                evidence_notes     TEXT,
                tested_by          BIGINT REFERENCES reach_actors(id),
                tested_at          TIMESTAMPTZ,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_disaster_recovery_tests CASCADE");
    }
}
