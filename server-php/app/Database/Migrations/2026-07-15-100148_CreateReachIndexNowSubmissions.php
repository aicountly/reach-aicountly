<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachIndexNowSubmissions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_indexnow_submissions (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id               BIGINT NOT NULL,
                content_identity_id     BIGINT REFERENCES reach_content_identities(id) ON DELETE SET NULL,
                url                     TEXT NOT NULL,
                provider_endpoint       TEXT NOT NULL,
                idempotency_key         VARCHAR(128) NOT NULL,
                status                  VARCHAR(20) NOT NULL DEFAULT 'pending'
                                        CHECK (status IN ('pending','submitted','failed','retrying','cancelled')),
                max_attempts            INT NOT NULL DEFAULT 3,
                attempt_count           INT NOT NULL DEFAULT 0,
                submitted_at            TIMESTAMPTZ,
                next_retry_at           TIMESTAMPTZ,
                completed_at            TIMESTAMPTZ,
                triggered_by            VARCHAR(20) NOT NULL DEFAULT 'job',
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_indexnow_idempotency UNIQUE (tenant_id, idempotency_key),
                CONSTRAINT uq_indexnow_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_indexnow_submissions_tenant ON reach_indexnow_submissions (tenant_id, status)");
        $this->db->query("CREATE INDEX idx_indexnow_submissions_retry ON reach_indexnow_submissions (next_retry_at) WHERE status = 'retrying'");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_indexnow_submissions CASCADE");
    }
}
