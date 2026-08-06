<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 0 — introduces the actor model.
 *
 *   actor_type IN ('human', 'system', 'bot', 'service')
 *
 * Added to every table that carries a `created_by` semantic where a
 * bot/system may originate a record. Also flags reach_users with
 * `is_login_disabled` so we can register non-login system/bot accounts
 * without ever allowing a password sign-in.
 */
class AddActorColumns extends Migration
{
    /** Tables that receive (created_actor_type, created_by_service, generation_job_id). */
    private const TABLES = [
        'reach_blog_posts',
        'reach_campaigns',
        'reach_social_posts',
        'reach_email_campaigns',
        'reach_whatsapp_campaigns',
        'reach_landing_pages',
        'reach_marketing_bot_queue',
        'reach_marketing_bot_reports',
        'reach_approvals',
    ];

    public function up(): void
    {
        // ADD COLUMN IF NOT EXISTS rather than fieldExists(): the field list is
        // cached per connection, so running this migration a second time in one
        // process (a test refresh, or a worker that migrates while running) read
        // the columns as already present, skipped them, and then failed on the
        // CHECK constraint that references them.
        $this->db->query('ALTER TABLE reach_users ADD COLUMN IF NOT EXISTS is_login_disabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->db->query("ALTER TABLE reach_users ADD COLUMN IF NOT EXISTS actor_type VARCHAR(16) NOT NULL DEFAULT 'human'");
        $this->db->query('ALTER TABLE reach_users DROP CONSTRAINT IF EXISTS reach_users_actor_type_chk');
        $this->db->query(
            "ALTER TABLE reach_users ADD CONSTRAINT reach_users_actor_type_chk CHECK (actor_type IN ('human','system','bot','service'))"
        );

        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table, false)) {
                continue;
            }
            $this->db->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS created_actor_type VARCHAR(16) NULL");
            $this->db->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS created_by_service VARCHAR(64) NULL");
            $this->db->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS generation_job_id BIGINT NULL");

            $checkName = "{$table}_created_actor_type_chk";
            $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$checkName}");
            $this->db->query(
                "ALTER TABLE {$table} ADD CONSTRAINT {$checkName} CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_created_actor_type_chk");
            foreach (['created_actor_type', 'created_by_service', 'generation_job_id'] as $col) {
                if ($this->db->fieldExists($col, $table)) {
                    $this->forge->dropColumn($table, $col);
                }
            }
        }

        if ($this->db->fieldExists('is_login_disabled', 'reach_users')) {
            $this->db->query("ALTER TABLE reach_users DROP CONSTRAINT IF EXISTS reach_users_actor_type_chk");
            $this->forge->dropColumn('reach_users', ['is_login_disabled', 'actor_type']);
        }
    }
}
