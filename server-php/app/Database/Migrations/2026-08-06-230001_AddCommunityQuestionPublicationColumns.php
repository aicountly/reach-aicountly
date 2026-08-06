<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Additive corrective migration.
 *
 * Phase 5 recorded the public site's identifiers for official *answers*
 * (public_external_id / public_url) but nothing for the question those answers
 * hang off, even though the publishing contract has always exposed
 * POST /reach/v1/community/questions. Without a stored public question id
 * Reach cannot tell whether the question record already exists on
 * aicountly.com, so it could never create one — and an answer published
 * against a non-existent question has nowhere to render.
 */
class AddCommunityQuestionPublicationColumns extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE reach_community_questions
                ADD COLUMN IF NOT EXISTS public_question_id   VARCHAR(255),
                ADD COLUMN IF NOT EXISTS public_question_slug VARCHAR(255),
                ADD COLUMN IF NOT EXISTS public_url           TEXT,
                ADD COLUMN IF NOT EXISTS published_at         TIMESTAMPTZ
        ');

        $this->db->query('
            CREATE INDEX IF NOT EXISTS idx_rcq_public_question
                ON reach_community_questions(public_question_id)
        ');
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE reach_community_questions
                DROP COLUMN IF EXISTS public_question_id,
                DROP COLUMN IF EXISTS public_question_slug,
                DROP COLUMN IF EXISTS public_url,
                DROP COLUMN IF EXISTS published_at
        ');
    }
}
