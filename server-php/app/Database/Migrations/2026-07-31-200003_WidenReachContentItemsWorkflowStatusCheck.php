<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CRITICAL FIX — reach_content_items.workflow_status carried a CHECK
 * constraint scoped only to App\Libraries\ContentWorkflowService's manual
 * vocabulary (idea, brief, draft, validation_pending, review_pending,
 * changes_requested, approved, scheduled, ready_for_publication, published,
 * refresh_due, archived, rejected).
 *
 * App\Libraries\Blog\BlogStateMachine writes a much larger, distinct set of
 * automation-native states (topic_candidate, brief_draft, draft_generating,
 * fact_verifying, publish_queued, publishing, live, ...) directly into the
 * same column. Every one of those writes outside the overlapping names
 * (idea, draft, approved, scheduled, published, rejected, archived) violated
 * this constraint and would throw at the database layer — meaning most
 * BlogStateMachine::transition() calls were never actually reachable in a
 * real Postgres environment. BlogStateMachineTest only exercised the
 * pure in-memory canTransition() adjacency check, so this was never caught.
 *
 * This migration widens the constraint to the union of both vocabularies so
 * both the manual Content Studio workflow and the automation-native blog
 * pipeline can coexist on this shared column, as the existing code already
 * assumes.
 */
class WidenReachContentItemsWorkflowStatusCheck extends Migration
{
    private const STATES = [
        // ContentWorkflowService (manual / Content Studio) vocabulary
        'idea', 'brief', 'draft', 'validation_pending', 'review_pending',
        'changes_requested', 'approved', 'scheduled', 'ready_for_publication',
        'published', 'refresh_due', 'archived', 'rejected',
        // BlogStateMachine (automation-native blog pipeline) vocabulary
        'topic_candidate', 'topic_scored', 'roadmap_planned',
        'brief_draft', 'brief_ready', 'outline_draft', 'outline_ready',
        'draft_generating', 'fact_verifying', 'fact_verified',
        'seo_review', 'internal_review', 'publish_queued', 'publishing',
        'verification_pending', 'live', 'refresh_pending', 'refresh_in_progress',
        'unpublish_queued', 'unpublished', 'failed', 'blocked',
    ];

    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_workflow_status_chk');

        $list = "'" . implode("','", self::STATES) . "'";
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_workflow_status_chk "
            . "CHECK (workflow_status IN ({$list}))"
        );
    }

    public function down(): void
    {
        // Feature tests run with $refresh = true (migrate down → up between
        // tests). By the time this down() runs, prior tests may have left
        // blog-automation statuses (live, topic_candidate, …) in the column.
        // Re-adding the narrower CHECK without normalizing those rows fails
        // with "check constraint … is violated by some row" and aborts the
        // entire Feature suite. Map any out-of-vocabulary value to archived
        // first so the rollback constraint can be applied cleanly.
        $narrow = [
            'idea', 'brief', 'draft', 'validation_pending', 'review_pending',
            'changes_requested', 'approved', 'scheduled', 'ready_for_publication',
            'published', 'refresh_due', 'archived', 'rejected',
        ];
        $list = "'" . implode("','", $narrow) . "'";

        $this->db->query('ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_workflow_status_chk');
        $this->db->query(
            "UPDATE reach_content_items SET workflow_status = 'archived' "
            . "WHERE workflow_status NOT IN ({$list})"
        );
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_workflow_status_chk "
            . "CHECK (workflow_status IN ({$list}))"
        );
    }
}
