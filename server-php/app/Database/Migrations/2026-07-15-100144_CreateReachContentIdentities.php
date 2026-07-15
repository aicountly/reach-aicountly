<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentIdentities extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_content_identities (
                id              BIGSERIAL PRIMARY KEY,
                uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id       BIGINT NOT NULL,
                content_type    VARCHAR(60) NOT NULL CHECK (content_type IN (
                                    'blog','kb_article','community_question','community_answer',
                                    'video','campaign_variant','landing_page','page'
                                )),
                source_id       BIGINT NOT NULL,
                canonical_url   TEXT,
                publication_status VARCHAR(30) NOT NULL DEFAULT 'unpublished'
                                CHECK (publication_status IN ('unpublished','published','withdrawn','noindex','redirected')),
                first_published_at TIMESTAMPTZ,
                last_published_at  TIMESTAMPTZ,
                content_version VARCHAR(40),
                analytics_eligible BOOLEAN NOT NULL DEFAULT TRUE,
                privacy_class   VARCHAR(20) NOT NULL DEFAULT 'public'
                                CHECK (privacy_class IN ('public','restricted','private')),
                source_repository VARCHAR(80),
                public_site_route TEXT,
                product_ids     BIGINT[],
                persona_ids     BIGINT[],
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_content_identity_source UNIQUE (tenant_id, content_type, source_id),
                CONSTRAINT uq_content_identity_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_content_identities_tenant ON reach_content_identities (tenant_id)");
        $this->db->query("CREATE INDEX idx_content_identities_type ON reach_content_identities (content_type)");
        $this->db->query("CREATE INDEX idx_content_identities_url ON reach_content_identities (canonical_url) WHERE canonical_url IS NOT NULL");
        $this->db->query("CREATE INDEX idx_content_identities_status ON reach_content_identities (publication_status)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_content_identities CASCADE");
    }
}
