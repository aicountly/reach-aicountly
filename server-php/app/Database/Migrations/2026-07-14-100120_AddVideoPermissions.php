<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVideoPermissions extends Migration
{
    private const PERMISSIONS = [
        'video.read',
        'video.create',
        'video.update',
        'video.generate',
        'video.submit',
        'video.review',
        'video.approve',
        'video.render',
        'video.publish',
        'video.cancel',
        'video.retry',
        'video_connections.read',
        'video_connections.manage',
        'video_operations.read',
        'video_audit.read',
    ];

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_permission_registry (
                id          BIGSERIAL    PRIMARY KEY,
                slug        VARCHAR(120) NOT NULL UNIQUE,
                phase       VARCHAR(20)  NOT NULL DEFAULT 'phase6',
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");

        foreach (self::PERMISSIONS as $slug) {
            $this->db->query(
                "INSERT INTO reach_video_permission_registry (slug) VALUES (?) ON CONFLICT (slug) DO NOTHING",
                [$slug]
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_permission_registry CASCADE');
    }
}
