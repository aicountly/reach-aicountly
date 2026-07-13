<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationIdempotencyRecords extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_idempotency_records (
                id                  BIGSERIAL PRIMARY KEY,
                idempotency_key     VARCHAR(255) NOT NULL UNIQUE,
                deployment_id       BIGINT REFERENCES reach_publication_deployments(id) ON DELETE SET NULL,
                operation           VARCHAR(32) NOT NULL,
                payload_checksum    VARCHAR(64) NOT NULL,
                status              VARCHAR(32) NOT NULL DEFAULT 'accepted',
                response_snapshot   JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at          TIMESTAMPTZ NOT NULL DEFAULT (NOW() + INTERVAL '30 days')
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_idempotency_key ON reach_publication_idempotency_records(idempotency_key)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_idempotency_expires ON reach_publication_idempotency_records(expires_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_publication_idempotency_records CASCADE');
    }
}
