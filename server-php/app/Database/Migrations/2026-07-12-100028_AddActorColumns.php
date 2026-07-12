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
        // Users: mark the system/bot actor as non-login.
        if (! $this->db->fieldExists('is_login_disabled', 'reach_users')) {
            $this->forge->addColumn('reach_users', [
                'is_login_disabled' => ['type' => 'BOOLEAN', 'default' => false, 'null' => false, 'after' => 'is_active'],
                'actor_type'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'human', 'null' => false, 'after' => 'role_id'],
            ]);
            $this->db->query(
                "ALTER TABLE reach_users ADD CONSTRAINT reach_users_actor_type_chk CHECK (actor_type IN ('human','system','bot','service'))"
            );
        }

        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $columns = [];
            if (! $this->db->fieldExists('created_actor_type', $table)) {
                $columns['created_actor_type'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true];
            }
            if (! $this->db->fieldExists('created_by_service', $table)) {
                $columns['created_by_service'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true];
            }
            if (! $this->db->fieldExists('generation_job_id', $table)) {
                $columns['generation_job_id'] = ['type' => 'BIGINT', 'null' => true];
            }
            if ($columns) {
                $this->forge->addColumn($table, $columns);
            }
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
