<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Members' questions from aicountly.com/community (stream 2, 2026-10-18).
 *
 * CommunityMemberQuestionFeedService pulls them (signed GET
 * /api/reach/v1/community/member-questions) into normal intake as
 * source_type public_submission, external_question_id "aicountly:<post id>",
 * public_external_id = the post id. These columns remember what the site last
 * said about each one, so an edit or a delete on the site is noticed:
 *
 *   source_checksum   sha256 of the site's title + body when last synced
 *   source_status     published | hidden | removed | deleted … on the site
 *   source_synced_at  when Reach last saw it
 */
class CommunityMemberQuestionFeed extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE reach_community_questions
                ADD COLUMN IF NOT EXISTS source_checksum  VARCHAR(64) NULL,
                ADD COLUMN IF NOT EXISTS source_status    VARCHAR(20) NULL,
                ADD COLUMN IF NOT EXISTS source_synced_at TIMESTAMPTZ NULL
        ');
        $this->db->query(
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_rcq_site_question
               ON reach_community_questions (external_question_id)
            WHERE source_type = 'public_submission' AND external_question_id IS NOT NULL"
        );
        if ($this->db->tableExists('reach_community_audit_event_registry')) {
            $this->db->query(
                "INSERT INTO reach_community_audit_event_registry (event_slug) VALUES ('community.member_feed.synced') ON CONFLICT (event_slug) DO NOTHING"
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS uq_rcq_site_question');
        $this->db->query('
            ALTER TABLE reach_community_questions
                DROP COLUMN IF EXISTS source_checksum,
                DROP COLUMN IF EXISTS source_status,
                DROP COLUMN IF EXISTS source_synced_at
        ');
    }
}
