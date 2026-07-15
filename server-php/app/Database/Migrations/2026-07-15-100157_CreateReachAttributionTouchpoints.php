<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAttributionTouchpoints extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_attribution_touchpoints (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id               BIGINT NOT NULL,
                visitor_pseudonym_hash  VARCHAR(64),
                utm_source              VARCHAR(120),
                utm_medium              VARCHAR(120),
                utm_campaign            VARCHAR(240),
                utm_content             VARCHAR(240),
                utm_term                VARCHAR(240),
                content_identity_id     BIGINT REFERENCES reach_content_identities(id) ON DELETE SET NULL,
                campaign_id             BIGINT,
                channel                 VARCHAR(40),
                touchpoint_type         VARCHAR(20) NOT NULL DEFAULT 'visit'
                                        CHECK (touchpoint_type IN ('click','visit','form_start','download','video_view')),
                source_event_ref        VARCHAR(120),
                referrer_domain         VARCHAR(120),
                touched_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_attribution_touchpoint_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_attribution_touchpoints_tenant ON reach_attribution_touchpoints (tenant_id, touched_at DESC)");
        $this->db->query("CREATE INDEX idx_attribution_touchpoints_visitor ON reach_attribution_touchpoints (visitor_pseudonym_hash) WHERE visitor_pseudonym_hash IS NOT NULL");
        $this->db->query("CREATE INDEX idx_attribution_touchpoints_identity ON reach_attribution_touchpoints (content_identity_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_attribution_touchpoints CASCADE");
    }
}
