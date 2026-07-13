<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationDeployments extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_deployments (
                id                  BIGSERIAL PRIMARY KEY,
                uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                content_item_id     BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE RESTRICT,
                content_version_id  BIGINT NOT NULL REFERENCES reach_content_versions(id) ON DELETE RESTRICT,
                connection_id       BIGINT NOT NULL REFERENCES reach_publication_connections(id) ON DELETE RESTRICT,
                operation           VARCHAR(32) NOT NULL
                                    CHECK (operation IN ('create_draft','update_draft','publish','schedule','unpublish','restore','rollback')),
                status              VARCHAR(32) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','ready','queued','sending','accepted','scheduled','published','verification_pending','verified','failed','blocked','cancelled','unpublished','rolled_back','superseded')),
                idempotency_key     VARCHAR(255) NOT NULL UNIQUE,
                request_id          VARCHAR(128),
                payload_checksum    VARCHAR(64),
                public_content_id   BIGINT,
                public_content_uuid UUID,
                public_version      INTEGER,
                canonical_url       VARCHAR(2048),
                scheduled_at        TIMESTAMPTZ,
                started_at          TIMESTAMPTZ,
                completed_at        TIMESTAMPTZ,
                attempt_count       SMALLINT NOT NULL DEFAULT 0,
                latest_attempt_id   BIGINT,
                error_category      VARCHAR(64),
                redacted_error      TEXT,
                created_by          BIGINT REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_deployments_item ON reach_publication_deployments(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_deployments_version ON reach_publication_deployments(content_version_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_deployments_status ON reach_publication_deployments(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_deployments_idempotency ON reach_publication_deployments(idempotency_key)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_deployments_public_id ON reach_publication_deployments(public_content_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_publication_deployments CASCADE');
    }
}
