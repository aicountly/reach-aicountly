<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachSitemapEntries extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_sitemap_entries (
                id                      BIGSERIAL PRIMARY KEY,
                snapshot_id             BIGINT NOT NULL REFERENCES reach_sitemap_snapshots(id) ON DELETE CASCADE,
                content_identity_id     BIGINT REFERENCES reach_content_identities(id) ON DELETE SET NULL,
                url                     TEXT NOT NULL,
                last_modified_at        TIMESTAMPTZ,
                change_frequency        VARCHAR(20) CHECK (change_frequency IN ('always','hourly','daily','weekly','monthly','yearly','never')),
                priority                NUMERIC(3,2) CHECK (priority BETWEEN 0.0 AND 1.0),
                included                BOOLEAN NOT NULL DEFAULT TRUE,
                exclusion_reason        VARCHAR(60),
                image_urls              TEXT[],
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX idx_sitemap_entries_snapshot ON reach_sitemap_entries (snapshot_id)");
        $this->db->query("CREATE INDEX idx_sitemap_entries_identity ON reach_sitemap_entries (content_identity_id)");
        $this->db->query("CREATE INDEX idx_sitemap_entries_url ON reach_sitemap_entries (url)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_sitemap_entries CASCADE");
    }
}
