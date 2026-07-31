<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — Community Steward "related questions" links.
 *
 * Distinct from reach_community_duplicate_clusters: a duplicate cluster
 * means "this is the same question"; a related link means "a reader of this
 * question may also want that one" — a much lower bar, and one the steward
 * role is explicitly allowed to curate without triggering duplicate-merge
 * workflows.
 */
class CreateReachCommunityQuestionRelatedLinks extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_question_related_links (
                id                  BIGSERIAL PRIMARY KEY,
                question_id         BIGINT NOT NULL REFERENCES reach_community_questions(id) ON DELETE CASCADE,
                related_question_id BIGINT NOT NULL REFERENCES reach_community_questions(id) ON DELETE CASCADE,
                similarity          DECIMAL(4,3) NOT NULL DEFAULT 0.000,
                created_by_identity_id BIGINT REFERENCES reach_community_official_identities(id) ON DELETE SET NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_rcqrl_not_self CHECK (question_id != related_question_id),
                CONSTRAINT uq_rcqrl_pair UNIQUE (question_id, related_question_id)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcqrl_question ON reach_community_question_related_links(question_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_question_related_links CASCADE');
    }
}
