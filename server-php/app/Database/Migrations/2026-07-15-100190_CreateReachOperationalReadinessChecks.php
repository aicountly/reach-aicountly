<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachOperationalReadinessChecks extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_operational_readiness_checks (
                id             BIGSERIAL PRIMARY KEY,
                check_category VARCHAR(50) NOT NULL
                    CHECK (check_category IN ('deployment','monitoring','backup','rollback','provider','migration')),
                check_name     VARCHAR(200) NOT NULL,
                status         VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','passed','failed','skipped','not_applicable')),
                evidence       TEXT,
                checked_at     TIMESTAMPTZ,
                checked_by     BIGINT REFERENCES reach_actors(id),
                UNIQUE (check_category, check_name)
            )
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_operational_readiness_checks CASCADE");
    }
}
