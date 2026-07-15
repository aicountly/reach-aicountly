<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshPublicationLinks extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_publication_links (
                id                    BIGSERIAL PRIMARY KEY,
                workflow_id           BIGINT NOT NULL REFERENCES reach_refresh_workflows(id),
                publication_attempt_id BIGINT,
                idempotency_key       VARCHAR(100) NOT NULL,
                published_at          TIMESTAMPTZ,
                delivery_status       VARCHAR(30) NOT NULL DEFAULT 'pending'
                    CHECK (delivery_status IN ('pending','queued','delivered','failed','retrying','cancelled')),
                retry_count           INT NOT NULL DEFAULT 0,
                created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (idempotency_key)
            )
        ");
        $this->db->query("CREATE INDEX reach_refresh_pub_links_workflow ON reach_refresh_publication_links (workflow_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_publication_links CASCADE");
    }
}
