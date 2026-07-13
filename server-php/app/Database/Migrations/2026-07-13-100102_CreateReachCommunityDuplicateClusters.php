<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityDuplicateClusters extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_duplicate_clusters (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                canonical_question_id   BIGINT NOT NULL REFERENCES reach_community_questions(id) ON DELETE RESTRICT,
                member_count            INT NOT NULL DEFAULT 1,
                similarity_algorithm    VARCHAR(40) NOT NULL DEFAULT 'embedding_cosine',
                similarity_threshold    DECIMAL(4,3) NOT NULL DEFAULT 0.850,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcdc_canonical ON reach_community_duplicate_clusters(canonical_question_id)');

        // Add FK back to duplicate_clusters from questions now that the table exists
        $this->db->query('ALTER TABLE reach_community_questions ADD CONSTRAINT fk_rcq_duplicate_cluster FOREIGN KEY (duplicate_cluster_id) REFERENCES reach_community_duplicate_clusters(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_community_questions DROP CONSTRAINT IF EXISTS fk_rcq_duplicate_cluster');
        $this->db->query('DROP TABLE IF EXISTS reach_community_duplicate_clusters CASCADE');
    }
}
