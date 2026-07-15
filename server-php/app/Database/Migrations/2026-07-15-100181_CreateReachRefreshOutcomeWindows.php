<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshOutcomeWindows extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_outcome_windows (
                id                   BIGSERIAL PRIMARY KEY,
                publication_link_id  BIGINT NOT NULL REFERENCES reach_refresh_publication_links(id),
                content_identity_id  BIGINT NOT NULL REFERENCES reach_content_identities(id),
                baseline_from        DATE NOT NULL,
                baseline_to          DATE NOT NULL,
                post_from            DATE NOT NULL,
                post_to              DATE NOT NULL,
                measurement_status   VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (measurement_status IN ('pending','partial','complete','insufficient_data')),
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (publication_link_id, baseline_from, post_from)
            )
        ");
        $this->db->query("CREATE INDEX reach_refresh_outcome_windows_content ON reach_refresh_outcome_windows (content_identity_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_outcome_windows CASCADE");
    }
}
