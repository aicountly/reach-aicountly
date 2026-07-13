<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationAttempts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_attempts (
                id              BIGSERIAL PRIMARY KEY,
                deployment_id   BIGINT NOT NULL REFERENCES reach_publication_deployments(id) ON DELETE CASCADE,
                attempt_number  SMALLINT NOT NULL,
                status          VARCHAR(32) NOT NULL
                                CHECK (status IN ('pending','sending','accepted','failed','timeout','retrying')),
                request_id      VARCHAR(128),
                http_status     SMALLINT,
                error_category  VARCHAR(64),
                redacted_error  TEXT,
                started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                completed_at    TIMESTAMPTZ,
                duration_ms     INTEGER,
                UNIQUE (deployment_id, attempt_number)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_pub_attempts_deployment ON reach_publication_attempts(deployment_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_pub_attempts_status ON reach_publication_attempts(status)');

        // Add FK from deployments to latest_attempt
        $this->db->query('
            ALTER TABLE reach_publication_deployments
            ADD CONSTRAINT fk_deployments_latest_attempt
            FOREIGN KEY (latest_attempt_id) REFERENCES reach_publication_attempts(id) ON DELETE SET NULL
        ');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_publication_deployments DROP CONSTRAINT IF EXISTS fk_deployments_latest_attempt');
        $this->db->query('DROP TABLE IF EXISTS reach_publication_attempts CASCADE');
    }
}
