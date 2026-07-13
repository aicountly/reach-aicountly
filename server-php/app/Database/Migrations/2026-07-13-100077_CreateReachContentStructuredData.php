<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentStructuredData extends Migration
{
    /** Approved JSON-LD schema types — no others permitted. */
    private const ALLOWED_TYPES = "'Article','BlogPosting','TechArticle','HowTo','FAQPage','BreadcrumbList','Organization','Person','WebPage','SoftwareApplication'";

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_structured_data (
                id                  BIGSERIAL PRIMARY KEY,
                content_item_id     BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                content_version_id  BIGINT REFERENCES reach_content_versions(id) ON DELETE SET NULL,
                schema_type         VARCHAR(64) NOT NULL
                                    CHECK (schema_type IN (" . self::ALLOWED_TYPES . ")),
                schema_json         JSONB NOT NULL,
                validation_status   VARCHAR(32) NOT NULL DEFAULT 'pending'
                                    CHECK (validation_status IN ('pending','valid','invalid','blocked')),
                validation_errors_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                is_primary          BOOLEAN NOT NULL DEFAULT FALSE,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_structured_data_item ON reach_content_structured_data(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_structured_data_version ON reach_content_structured_data(content_version_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_structured_data_status ON reach_content_structured_data(validation_status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_content_structured_data CASCADE');
    }
}
