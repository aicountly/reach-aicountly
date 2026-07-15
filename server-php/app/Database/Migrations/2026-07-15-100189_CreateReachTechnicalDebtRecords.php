<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachTechnicalDebtRecords extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_technical_debt_records (
                id               BIGSERIAL PRIMARY KEY,
                tenant_id        BIGINT NOT NULL REFERENCES reach_actors(id),
                classification   VARCHAR(30) NOT NULL
                    CHECK (classification IN (
                        'critical_blocker','high_blocker','release_limitation',
                        'accepted_medium','accepted_low','deferred','superseded','out_of_scope'
                    )),
                title            VARCHAR(200) NOT NULL,
                description      TEXT NOT NULL,
                impact           TEXT NOT NULL,
                workaround       TEXT,
                owner            BIGINT REFERENCES reach_actors(id),
                target_date      DATE,
                acceptance_reason TEXT,
                accepted_by      BIGINT REFERENCES reach_actors(id),
                accepted_at      TIMESTAMPTZ,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX reach_technical_debt_records_class ON reach_technical_debt_records (tenant_id, classification)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_technical_debt_records CASCADE");
    }
}
