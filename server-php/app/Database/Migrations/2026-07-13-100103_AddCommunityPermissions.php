<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Documents Phase 5 community.* permission constants.
 *
 * Actual permission enforcement uses the constants in Config/Permissions.php
 * and the PermissionService. This migration is a reference record; it creates
 * a no-op table row so the migration system tracks the addition.
 */
class AddCommunityPermissions extends Migration
{
    private const PERMISSIONS = [
        'community.view',
        'community.intake.create',
        'community.intake.import',
        'community.question.edit',
        'community.question.classify',
        'community.question.moderate',
        'community.answer.generate',
        'community.answer.edit',
        'community.answer.review',
        'community.answer.professional_review',
        'community.answer.approve',
        'community.answer.schedule',
        'community.answer.publish',
        'community.answer.unpublish',
        'community.answer.restore',
        'community.answer.withdraw',
        'community.answer.override_validation',
        'community.identity.manage',
        'community.settings.manage',
        'community.analytics.view',
        'community.audit.view',
        'community.engagement.ingest',
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
