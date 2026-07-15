<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAiVisibilityCitations extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_ai_visibility_citations (
                id                          BIGSERIAL PRIMARY KEY,
                uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
                response_id                 BIGINT NOT NULL REFERENCES reach_ai_visibility_responses(id) ON DELETE RESTRICT,
                observation_id              BIGINT REFERENCES reach_ai_visibility_observations(id) ON DELETE SET NULL,
                cited_url                   TEXT,
                cited_domain                VARCHAR(120),
                citation_type               VARCHAR(20) NOT NULL DEFAULT 'reference'
                                            CHECK (citation_type IN ('source','link','reference','mention')),
                linked_content_identity_id  BIGINT REFERENCES reach_content_identities(id) ON DELETE SET NULL,
                citation_order              INT,
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_visibility_citation_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_visibility_citations_response ON reach_ai_visibility_citations (response_id)");
        $this->db->query("CREATE INDEX idx_visibility_citations_domain ON reach_ai_visibility_citations (cited_domain)");
        $this->db->query("CREATE INDEX idx_visibility_citations_identity ON reach_ai_visibility_citations (linked_content_identity_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_visibility_citations CASCADE");
    }
}
