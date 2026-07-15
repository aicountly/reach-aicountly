<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentMappingFindings extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_content_mapping_findings (
                id                  BIGSERIAL PRIMARY KEY,
                connection_id       BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE CASCADE,
                ingestion_run_id    BIGINT,
                unmapped_url        TEXT NOT NULL,
                finding_type        VARCHAR(40) NOT NULL CHECK (finding_type IN ('unmapped','conflict','duplicate_canonical','redirect_detected')),
                resolution_status   VARCHAR(20) NOT NULL DEFAULT 'unresolved'
                                    CHECK (resolution_status IN ('unresolved','resolved','suppressed','auto_resolved')),
                resolved_identity_id BIGINT REFERENCES reach_content_identities(id) ON DELETE SET NULL,
                resolved_at         TIMESTAMPTZ,
                resolved_by         BIGINT,
                suppressed_reason   TEXT,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX idx_mapping_findings_connection ON reach_content_mapping_findings (connection_id, resolution_status)");
        $this->db->query("CREATE INDEX idx_mapping_findings_url ON reach_content_mapping_findings (unmapped_url)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_content_mapping_findings CASCADE");
    }
}
