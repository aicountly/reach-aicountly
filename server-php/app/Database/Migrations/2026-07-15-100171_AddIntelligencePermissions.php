<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIntelligencePermissions extends Migration
{
    private array $permissions = [
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
        $existing = $this->db->query("SELECT slug FROM reach_permissions")->getResultArray();
        $existingSlugs = array_column($existing, 'slug');

        foreach ($this->permissions as $slug) {
            if (!in_array($slug, $existingSlugs, true)) {
                [$group, $action] = explode('.', $slug, 2);
                $this->db->query(
                    "INSERT INTO reach_permissions (slug, group_name, action, description, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())",
                    [$slug, $group, $action, "Phase 8 intelligence permission: {$slug}"]
                );
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $slug) {
            $this->db->query("DELETE FROM reach_permissions WHERE slug = ?", [$slug]);
        }
    }
}
