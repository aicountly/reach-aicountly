<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachIndexNowAttempts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_indexnow_attempts (
                id              BIGSERIAL PRIMARY KEY,
                submission_id   BIGINT NOT NULL REFERENCES reach_indexnow_submissions(id) ON DELETE CASCADE,
                attempt_number  INT NOT NULL,
                http_status     INT,
                provider_response TEXT,
                error_message   TEXT,
                latency_ms      INT,
                attempted_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                succeeded       BOOLEAN NOT NULL DEFAULT FALSE,
                CONSTRAINT uq_indexnow_attempt UNIQUE (submission_id, attempt_number)
            )
        ");
        $this->db->query("CREATE INDEX idx_indexnow_attempts_submission ON reach_indexnow_attempts (submission_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_indexnow_attempts CASCADE");
    }
}
