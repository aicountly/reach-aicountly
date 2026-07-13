<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationVerifications extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_verifications (
                id                  BIGSERIAL PRIMARY KEY,
                deployment_id       BIGINT NOT NULL REFERENCES reach_publication_deployments(id) ON DELETE CASCADE,
                verification_type   VARCHAR(32) NOT NULL
                                    CHECK (verification_type IN ('public_status','content_version','payload_checksum','canonical_url','rendered_page','title','body_hash','structured_data','sitemap','robots','internal_links')),
                expected_value      TEXT,
                actual_value        TEXT,
                status              VARCHAR(32) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','passed','failed','skipped','error')),
                checked_at          TIMESTAMPTZ,
                http_status         SMALLINT,
                response_hash       VARCHAR(64),
                details_json        JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_verifications_deployment ON reach_publication_verifications(deployment_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_verifications_type ON reach_publication_verifications(verification_type)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_verifications_status ON reach_publication_verifications(status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_publication_verifications CASCADE');
    }
}
