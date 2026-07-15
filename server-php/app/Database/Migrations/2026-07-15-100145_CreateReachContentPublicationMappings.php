<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentPublicationMappings extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_content_publication_mappings (
                id                      BIGSERIAL PRIMARY KEY,
                content_identity_id     BIGINT NOT NULL REFERENCES reach_content_identities(id) ON DELETE CASCADE,
                platform                VARCHAR(40) NOT NULL CHECK (platform IN ('gsc','ga4','youtube','linkedin','twitter','email','indexnow','sitemap')),
                remote_identifier       TEXT NOT NULL,
                remote_url              TEXT,
                verified_at             TIMESTAMPTZ,
                verification_method     VARCHAR(40),
                is_primary              BOOLEAN NOT NULL DEFAULT FALSE,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_content_pub_mapping UNIQUE (content_identity_id, platform, remote_identifier)
            )
        ");
        $this->db->query("CREATE INDEX idx_pub_mappings_identity ON reach_content_publication_mappings (content_identity_id)");
        $this->db->query("CREATE INDEX idx_pub_mappings_platform ON reach_content_publication_mappings (platform)");
        $this->db->query("CREATE INDEX idx_pub_mappings_remote ON reach_content_publication_mappings (remote_identifier)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_content_publication_mappings CASCADE");
    }
}
