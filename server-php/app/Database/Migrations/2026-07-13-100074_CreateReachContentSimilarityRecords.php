<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_content_similarity_records for duplicate and near-duplicate detection.
 * No external vector database. Uses PostgreSQL hashes and pg_trgm similarity where available.
 */
class CreateReachContentSimilarityRecords extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE EXTENSION IF NOT EXISTS pg_trgm");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_similarity_records (
                id                          BIGSERIAL PRIMARY KEY,
                uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
                content_item_id             BIGINT NOT NULL REFERENCES reach_content_items(id),
                content_version_id          BIGINT NOT NULL,
                exact_body_hash             VARCHAR(64),
                normalised_text_hash        VARCHAR(64),
                title_normalised            TEXT,
                slug_normalised             VARCHAR(256),
                compared_item_id            BIGINT REFERENCES reach_content_items(id),
                compared_version_id         BIGINT,
                title_similarity_score      NUMERIC(5,4),
                body_similarity_score       NUMERIC(5,4),
                slug_similarity_score       NUMERIC(5,4),
                overall_similarity_score    NUMERIC(5,4),
                is_duplicate                BOOLEAN NOT NULL DEFAULT FALSE,
                is_near_duplicate           BOOLEAN NOT NULL DEFAULT FALSE,
                similarity_threshold        NUMERIC(5,4) NOT NULL DEFAULT 0.8,
                compared_at                 TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_content_sim_uuid ON reach_content_similarity_records (uuid)");
        $this->db->query("CREATE INDEX idx_content_sim_item ON reach_content_similarity_records (content_item_id)");
        $this->db->query("CREATE INDEX idx_content_sim_body_hash ON reach_content_similarity_records (exact_body_hash) WHERE exact_body_hash IS NOT NULL");
        $this->db->query("CREATE INDEX idx_content_sim_norm_hash ON reach_content_similarity_records (normalised_text_hash) WHERE normalised_text_hash IS NOT NULL");
        $this->db->query("CREATE INDEX idx_content_sim_duplicates ON reach_content_similarity_records (is_duplicate) WHERE is_duplicate = TRUE");

        $this->db->query("CREATE INDEX IF NOT EXISTS idx_content_sim_title_trgm ON reach_content_similarity_records USING gin (title_normalised gin_trgm_ops)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_content_similarity_records");
    }
}
