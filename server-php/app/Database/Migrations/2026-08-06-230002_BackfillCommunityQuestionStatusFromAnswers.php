<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One-shot repair for questions stranded at `draft_requested`.
 *
 * Until CommunityQuestionStatusMirror was introduced, the only question
 * transition Reach ever performed was the hop to `draft_requested` taken when a
 * draft answer was reserved. Every question that reached that point therefore
 * still reports `draft_requested` no matter how far its answer travelled.
 *
 * The mirror keeps new work in step, but it only fires on the *next* answer
 * transition, so existing rows are corrected here: the answer status is the
 * source of truth and both enums share their case names.
 *
 * Questions an operator deliberately parked (archived, duplicate_merged) and
 * questions with no answer at all are left alone.
 */
class BackfillCommunityQuestionStatusFromAnswers extends Migration
{
    public function up(): void
    {
        $this->db->query("
            UPDATE reach_community_questions q
               SET status     = a.status,
                   updated_at = NOW()
              FROM reach_community_official_answers a
             WHERE a.question_id = q.id
               AND q.status <> a.status
               AND q.status NOT IN ('archived', 'duplicate_merged')
        ");

        // Answers already live on aicountly.com carry the public URL; lift it
        // onto the question so the inbox can link out to the published page
        // without waiting for the next publication.
        $this->db->query("
            UPDATE reach_community_questions q
               SET public_url   = split_part(a.public_url, '#', 1),
                   published_at = COALESCE(q.published_at, NOW())
              FROM reach_community_official_answers a
             WHERE a.question_id = q.id
               AND a.public_url IS NOT NULL
               AND a.public_url <> ''
               AND q.public_url IS NULL
        ");
    }

    public function down(): void
    {
        // Deriving the previous (incorrect) statuses is not possible, and
        // restoring them would only reintroduce the stranded rows.
    }
}
