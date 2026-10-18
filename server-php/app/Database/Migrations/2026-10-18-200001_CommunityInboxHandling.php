<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Community Question Inbox — who handles each question (stream 2, 2026-10-18).
 *
 * The inbox stalled because nothing recorded, or moved, a question once a
 * draft had been requested: a question's status stopped at draft_requested
 * even after its answer was published, legacy in-Reach drafts blocked the
 * routine until a person ran community:legacy-drafts, and questions the desks
 * would never answer (personal data, two failed answers) simply sat there.
 * CommunityInboxAutomationService now keeps these columns, and the inbox
 * shows them:
 *
 *   handled_by          routine_queue | routine_vetting | publishing | published | auto_closed | closed
 *   handling_note       what happened / why, in a sentence
 *   handling_updated_at when handled_by last changed
 *   routine_queued_at   when the question was first offered to the community-qa routine
 *   closed_at / close_reason / answered_by_question_id
 *                       a bot close: duplicate (linked to the answered question), rejected,
 *                       personal_data, moderation, spam, prohibited, unanswerable, declined, unscreenable
 *   triage_*            the vetting model's screen of a member's question (community_question rubric)
 *   automation_failures bounded retry counter for screening/triage, so no row is retried forever
 */
class CommunityInboxHandling extends Migration
{
    private const EVENTS = ['community.inbox.queued', 'community.inbox.closed', 'community.inbox.published'];

    public function up(): void
    {
        $this->db->query("
            ALTER TABLE reach_community_questions
                ADD COLUMN IF NOT EXISTS handled_by              VARCHAR(24) NULL,
                ADD COLUMN IF NOT EXISTS handling_note           TEXT        NULL,
                ADD COLUMN IF NOT EXISTS handling_updated_at     TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS routine_queued_at       TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS closed_at               TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS close_reason            VARCHAR(32) NULL,
                ADD COLUMN IF NOT EXISTS answered_by_question_id BIGINT      NULL,
                ADD COLUMN IF NOT EXISTS triage_decision         VARCHAR(16) NULL,
                ADD COLUMN IF NOT EXISTS triage_vetting_run_id   BIGINT      NULL,
                ADD COLUMN IF NOT EXISTS triage_checked_at       TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS automation_failures     SMALLINT    NOT NULL DEFAULT 0
        ");

        $this->db->query('ALTER TABLE reach_community_questions DROP CONSTRAINT IF EXISTS chk_rcq_handled_by');
        $this->db->query("
            ALTER TABLE reach_community_questions ADD CONSTRAINT chk_rcq_handled_by CHECK (
                handled_by IS NULL OR handled_by IN
                ('routine_queue','routine_vetting','publishing','published','auto_closed','closed')
            )
        ");
        $this->db->query('ALTER TABLE reach_community_questions DROP CONSTRAINT IF EXISTS fk_rcq_answered_by');
        $this->db->query('
            ALTER TABLE reach_community_questions ADD CONSTRAINT fk_rcq_answered_by
                FOREIGN KEY (answered_by_question_id) REFERENCES reach_community_questions(id) ON DELETE SET NULL
        ');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcq_handled_by ON reach_community_questions(handled_by)');

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
        $this->db->query('DROP INDEX IF EXISTS idx_rcq_handled_by');
        $this->db->query('ALTER TABLE reach_community_questions DROP CONSTRAINT IF EXISTS fk_rcq_answered_by');
        $this->db->query('ALTER TABLE reach_community_questions DROP CONSTRAINT IF EXISTS chk_rcq_handled_by');
        $this->db->query('
            ALTER TABLE reach_community_questions
                DROP COLUMN IF EXISTS handled_by,
                DROP COLUMN IF EXISTS handling_note,
                DROP COLUMN IF EXISTS handling_updated_at,
                DROP COLUMN IF EXISTS routine_queued_at,
                DROP COLUMN IF EXISTS closed_at,
                DROP COLUMN IF EXISTS close_reason,
                DROP COLUMN IF EXISTS answered_by_question_id,
                DROP COLUMN IF EXISTS triage_decision,
                DROP COLUMN IF EXISTS triage_vetting_run_id,
                DROP COLUMN IF EXISTS triage_checked_at,
                DROP COLUMN IF EXISTS automation_failures
        ');
    }
}
