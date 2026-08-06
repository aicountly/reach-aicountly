<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registers the community deletion audit event slugs.
 *
 * Mirrors 2026-07-13-100104_AddCommunityAuditEventConstants: the registry is a
 * reference record of which events exist, the enforcement lives in
 * App\Libraries\AuditLogger.
 */
class AddCommunityDeletionAuditEvents extends Migration
{
    private const EVENTS = [
        'community.question.deleted',
        'community.answer.deleted',
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
                'INSERT INTO reach_community_audit_event_registry (event_slug) VALUES (?) ON CONFLICT (event_slug) DO NOTHING',
                [$slug]
            );
        }
    }

    public function down(): void
    {
        foreach (self::EVENTS as $slug) {
            $this->db->query('DELETE FROM reach_community_audit_event_registry WHERE event_slug = ?', [$slug]);
        }
    }
}
