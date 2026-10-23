<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Live community answers fixed by the bot after re-vetting (reach:content-revet
 * --community and the daily quality sweep).
 *
 * 1. reach_community_reanswers — one row per live answer the vetting model
 *    found wrong (reject) or wanting changes (revise without usable text, or a
 *    vetting revision that did not survive the pipeline). The question is
 *    offered to the reach-community-qa routine as a priority re-answer item
 *    (GET v1/automation/community/pending, `reanswer: true`, no quota); the
 *    routine's new text becomes a correction version of the SAME answer, so it
 *    is republished at the same URL.
 *      mode            withdrawn (taken down: a reject) | kept_live (a revise: the
 *                      current text stays up until the replacement is live)
 *      status          queued (waiting for the routine) | submitted (a correction is
 *                      being vetted/published) | live | cancelled
 *      replaced_state  the answer row as it was before the correction started, so
 *                      a correction that is not published puts it back exactly
 * 2. reach_community_automation_submissions.origin — 'routine' (counts against
 *    the daily quota) or 'revet' (a repair of an answer that is already public;
 *    does not count), plus reanswer_id; kind gains 'correction' (the vetting
 *    model's own revised text, published as a correction version).
 */
class CommunityAnswerRevetRepair extends Migration
{
    private const EVENTS = [
        'community.answer.revet_withdrawn', 'community.answer.revet_correction',
        'community.answer.reanswer_queued', 'community.answer.reanswer_live', 'community.answer.reanswer_cancelled',
    ];

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_reanswers (
                id               BIGSERIAL PRIMARY KEY,
                question_id      BIGINT       NOT NULL REFERENCES reach_community_questions(id) ON DELETE CASCADE,
                answer_id        BIGINT       NOT NULL REFERENCES reach_community_official_answers(id) ON DELETE CASCADE,
                mode             VARCHAR(16)  NOT NULL CHECK (mode IN ('withdrawn','kept_live')),
                source           VARCHAR(16)  NOT NULL DEFAULT 'revise' CHECK (source IN ('reject','revise')),
                reasons          JSONB        NOT NULL DEFAULT '[]'::jsonb,
                status           VARCHAR(16)  NOT NULL DEFAULT 'queued'
                                     CHECK (status IN ('queued','submitted','live','cancelled')),
                attempts         INTEGER      NOT NULL DEFAULT 0,
                submission_id    BIGINT       NULL,
                quality_check_id BIGINT       NULL,
                replaced_state   JSONB        NULL,
                last_error       TEXT         NULL,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                completed_at     TIMESTAMPTZ  NULL
            )
        ");
        $this->db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_rcra_open_answer ON reach_community_reanswers (answer_id)
                          WHERE status IN ('queued','submitted')");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcra_status ON reach_community_reanswers (status, id)');

        $this->db->query("ALTER TABLE reach_community_automation_submissions ADD COLUMN IF NOT EXISTS origin VARCHAR(16) NOT NULL DEFAULT 'routine'");
        $this->db->query('ALTER TABLE reach_community_automation_submissions ADD COLUMN IF NOT EXISTS reanswer_id BIGINT NULL');
        $this->db->query('ALTER TABLE reach_community_automation_submissions DROP CONSTRAINT IF EXISTS reach_community_automation_submissions_origin_chk');
        $this->db->query("ALTER TABLE reach_community_automation_submissions ADD CONSTRAINT reach_community_automation_submissions_origin_chk
                          CHECK (origin IN ('routine','revet'))");
        $this->db->query('ALTER TABLE reach_community_automation_submissions DROP CONSTRAINT IF EXISTS reach_community_automation_submissions_kind_check');
        $this->db->query("ALTER TABLE reach_community_automation_submissions ADD CONSTRAINT reach_community_automation_submissions_kind_check
                          CHECK (kind IN ('faq','member_answer','correction'))");

        if ($this->db->tableExists('reach_community_audit_event_registry')) {
            foreach (self::EVENTS as $slug) {
                $this->db->query(
                    'INSERT INTO reach_community_audit_event_registry (event_slug) VALUES (?) ON CONFLICT (event_slug) DO NOTHING',
                    [$slug]
                );
            }
        }
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM reach_community_automation_submissions WHERE kind = 'correction'");
        $this->db->query('ALTER TABLE reach_community_automation_submissions DROP CONSTRAINT IF EXISTS reach_community_automation_submissions_kind_check');
        $this->db->query("ALTER TABLE reach_community_automation_submissions ADD CONSTRAINT reach_community_automation_submissions_kind_check
                          CHECK (kind IN ('faq','member_answer'))");
        $this->db->query('ALTER TABLE reach_community_automation_submissions DROP CONSTRAINT IF EXISTS reach_community_automation_submissions_origin_chk');
        $this->db->query('ALTER TABLE reach_community_automation_submissions DROP COLUMN IF EXISTS reanswer_id');
        $this->db->query('ALTER TABLE reach_community_automation_submissions DROP COLUMN IF EXISTS origin');
        $this->db->query('DROP TABLE IF EXISTS reach_community_reanswers');
    }
}
