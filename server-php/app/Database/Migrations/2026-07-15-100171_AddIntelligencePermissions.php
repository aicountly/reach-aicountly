<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Documents Phase 8 intelligence permission constants.
 *
 * Actual permission enforcement uses Config/Permissions.php and PermissionService.
 * This migration creates a registry table and inserts the canonical slugs.
 */
class AddIntelligencePermissions extends Migration
{
    private const PERMISSIONS = [
        'intelligence.read',
        'intelligence.manage',
        'intelligence.operations',
        'intelligence.audit',
        'search.read',
        'search.connect',
        'search.ingest',
        'search.backfill',
        'search.reconcile',
        'sitemap.read',
        'sitemap.manage',
        'sitemap.submit',
        'sitemap.reconcile',
        'analytics.read',
        'analytics.connect',
        'analytics.ingest',
        'analytics.backfill',
        'analytics.reconcile',
        'attribution.read',
        'attribution.manage',
        'attribution.reconcile',
        'attribution.correct',
        'visibility.read',
        'visibility.manage',
        'visibility.execute',
        'visibility.schedule',
        'visibility.review',
        'competitor.read',
        'competitor.manage',
        'connector.read',
        'connector.manage',
        'connector.retry',
    ];

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_intelligence_permission_registry (
                id          BIGSERIAL    PRIMARY KEY,
                slug        VARCHAR(120) NOT NULL UNIQUE,
                phase       VARCHAR(20)  NOT NULL DEFAULT 'phase8',
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");

        foreach (self::PERMISSIONS as $slug) {
            $this->db->query(
                "INSERT INTO reach_intelligence_permission_registry (slug) VALUES (?) ON CONFLICT (slug) DO NOTHING",
                [$slug]
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_intelligence_permission_registry CASCADE');
    }
}
