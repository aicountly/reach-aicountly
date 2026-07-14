<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Documents Phase 5 community permission constants.
 *
 * Actual permission enforcement uses the constants in Config/Permissions.php
 * and the PermissionService. This migration is a reference record; it creates
 * a registry table and inserts the canonical slugs.
 *
 * Slug format: all values use the established two-segment group.action format.
 * Sub-domains use underscored group prefixes so that no slug contains more
 * than one dot.
 *
 * Correction history (Phase 5 remediation — not yet pushed/deployed):
 *   community.intake.create              → community_intake.create
 *   community.intake.import              → community_intake.import
 *   community.question.edit              → community_question.edit
 *   community.question.classify          → community_question.classify
 *   community.question.moderate          → community_question.moderate
 *   community.answer.generate            → community_answer.generate
 *   community.answer.edit                → community_answer.edit
 *   community.answer.review              → community_answer.review
 *   community.answer.professional_review → community_answer.professional_review
 *   community.answer.approve             → community_answer.approve
 *   community.answer.schedule            → community_answer.schedule
 *   community.answer.publish             → community_answer.publish
 *   community.answer.unpublish           → community_answer.unpublish
 *   community.answer.restore             → community_answer.restore
 *   community.answer.withdraw            → community_answer.withdraw
 *   community.answer.override_validation → community_answer.override_validation
 *   community.identity.manage            → community_identity.manage
 *   community.settings.manage            → community_settings.manage
 *   community.analytics.view             → community_analytics.view
 *   community.audit.view                 → community_audit.view
 *   community.engagement.ingest          → community_engagement.ingest
 *   community.view                       → community.view  (unchanged — already two-segment)
 */
class AddCommunityPermissions extends Migration
{
    private const PERMISSIONS = [
        'community.view',
        'community_intake.create',
        'community_intake.import',
        'community_question.edit',
        'community_question.classify',
        'community_question.moderate',
        'community_answer.generate',
        'community_answer.edit',
        'community_answer.review',
        'community_answer.professional_review',
        'community_answer.approve',
        'community_answer.schedule',
        'community_answer.publish',
        'community_answer.unpublish',
        'community_answer.restore',
        'community_answer.withdraw',
        'community_answer.override_validation',
        'community_identity.manage',
        'community_settings.manage',
        'community_analytics.view',
        'community_audit.view',
        'community_engagement.ingest',
    ];

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_permission_registry (
                id          BIGSERIAL PRIMARY KEY,
                slug        VARCHAR(120) NOT NULL UNIQUE,
                phase       VARCHAR(20) NOT NULL DEFAULT 'phase5',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        foreach (self::PERMISSIONS as $slug) {
            $this->db->query(
                "INSERT INTO reach_community_permission_registry (slug) VALUES (?) ON CONFLICT (slug) DO NOTHING",
                [$slug]
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_permission_registry CASCADE');
    }
}
