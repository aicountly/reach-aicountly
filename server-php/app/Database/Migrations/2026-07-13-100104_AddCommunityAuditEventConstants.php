<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Documents Phase 5 community.* audit event constants.
 *
 * AuditLogger uses free-form event strings; this migration creates a reference
 * registry so developers can query which events exist and which phase introduced them.
 */
class AddCommunityAuditEventConstants extends Migration
{
    private const EVENTS = [
        'community.question.intake',
        'community.question.import',
        'community.question.classified',
        'community.question.triage_scored',
        'community.question.assigned',
        'community.question.duplicate_detected',
        'community.question.duplicate_merged',
        'community.question.moderated',
        'community.question.archived',
        'community.answer.generation_requested',
        'community.answer.generation_started',
        'community.answer.generation_completed',
        'community.answer.generation_failed',
        'community.answer.draft_created',
        'community.answer.version_created',
        'community.answer.source_attached',
        'community.answer.validation_passed',
        'community.answer.validation_failed',
        'community.answer.moderation_finding',
        'community.answer.moderation_override',
        'community.answer.review_requested',
        'community.answer.review_completed',
        'community.answer.professional_review_requested',
        'community.answer.approval_submitted',
        'community.answer.approved',
        'community.answer.rejected',
        'community.answer.changes_requested',
        'community.answer.scheduled',
        'community.answer.publishing',
        'community.answer.published',
        'community.answer.verification_passed',
        'community.answer.verification_failed',
        'community.answer.checksum_mismatch',
        'community.answer.retry',
        'community.answer.reconciliation',
        'community.answer.correction_started',
        'community.answer.corrected',
        'community.answer.unpublished',
        'community.answer.withdrawn',
        'community.answer.restored',
        'community.identity.created',
        'community.identity.updated',
        'community.identity.deactivated',
        'community.settings.changed',
        'community.engagement.ingested',
        'community.engagement.bot_filtered',
        'community.publishing.checksum_mismatch',
        'community.publishing.rollback',
    ];

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_audit_event_registry (
                id          BIGSERIAL PRIMARY KEY,
                event_slug  VARCHAR(120) NOT NULL UNIQUE,
                phase       VARCHAR(20) NOT NULL DEFAULT 'phase5',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        foreach (self::EVENTS as $slug) {
            $this->db->query(
                "INSERT INTO reach_community_audit_event_registry (event_slug) VALUES (?) ON CONFLICT (event_slug) DO NOTHING",
                [$slug]
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_audit_event_registry CASCADE');
    }
}
