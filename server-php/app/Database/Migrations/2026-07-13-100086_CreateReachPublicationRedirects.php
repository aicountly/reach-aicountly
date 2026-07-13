<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationRedirects extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_redirects (
                id                  BIGSERIAL PRIMARY KEY,
                content_item_id     BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                from_slug           VARCHAR(300) NOT NULL,
                to_slug             VARCHAR(300),
                to_url              VARCHAR(2048),
                redirect_type       SMALLINT NOT NULL DEFAULT 301 CHECK (redirect_type IN (301,302)),
                reason              VARCHAR(255),
                status              VARCHAR(32) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','active','superseded','cancelled')),
                deployment_id       BIGINT REFERENCES reach_publication_deployments(id) ON DELETE SET NULL,
                applied_at          TIMESTAMPTZ,
                created_by          BIGINT REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_redirects_item ON reach_publication_redirects(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_redirects_from_slug ON reach_publication_redirects(from_slug)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_redirects_status ON reach_publication_redirects(status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_publication_redirects CASCADE');
    }
}
