<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationRefreshReviews extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_refresh_reviews (
                id                  BIGSERIAL PRIMARY KEY,
                content_item_id     BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                deployment_id       BIGINT REFERENCES reach_publication_deployments(id) ON DELETE SET NULL,
                trigger_type        VARCHAR(64) NOT NULL
                                    CHECK (trigger_type IN ('review_date_reached','source_expired','citation_invalidated','product_claim_changed','feature_availability_changed','broken_link','major_product_release','manual_request')),
                trigger_detail      TEXT,
                status              VARCHAR(32) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','in_progress','completed','dismissed')),
                requested_by        BIGINT REFERENCES reach_actors(id) ON DELETE SET NULL,
                assigned_to         BIGINT REFERENCES reach_actors(id) ON DELETE SET NULL,
                due_at              TIMESTAMPTZ,
                completed_at        TIMESTAMPTZ,
                notes               TEXT,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_refresh_reviews_item ON reach_publication_refresh_reviews(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_refresh_reviews_status ON reach_publication_refresh_reviews(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_refresh_reviews_due ON reach_publication_refresh_reviews(due_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_publication_refresh_reviews CASCADE');
    }
}
