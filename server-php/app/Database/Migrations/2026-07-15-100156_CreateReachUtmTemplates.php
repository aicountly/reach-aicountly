<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachUtmTemplates extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_utm_templates (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id               BIGINT NOT NULL,
                name                    VARCHAR(120) NOT NULL,
                description             TEXT,
                utm_source              VARCHAR(120) NOT NULL,
                utm_medium              VARCHAR(120) NOT NULL,
                utm_campaign_template   VARCHAR(240),
                utm_content_template    VARCHAR(240),
                utm_term_template       VARCHAR(240),
                channel_hint            VARCHAR(40),
                is_active               BOOLEAN NOT NULL DEFAULT TRUE,
                created_by              BIGINT,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_utm_template_uuid UNIQUE (uuid),
                CONSTRAINT uq_utm_template_name UNIQUE (tenant_id, name)
            )
        ");
        $this->db->query("CREATE INDEX idx_utm_templates_tenant ON reach_utm_templates (tenant_id, is_active)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_utm_templates CASCADE");
    }
}
