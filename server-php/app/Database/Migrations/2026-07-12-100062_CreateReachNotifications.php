<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — In-app notification system.
 *
 * Three tables: notifications (per-user), preferences (delivery settings),
 * and deliveries (per-channel delivery records).
 * Email delivery is scaffolded but disabled in Phase 2 (flag in preferences).
 */
class CreateReachNotifications extends Migration
{
    public function up(): void
    {
        // ── Notifications ────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'uuid'              => ['type' => 'UUID', 'null' => false, 'default' => new RawSql('gen_random_uuid()')],
            'recipient_id'      => ['type' => 'BIGINT', 'null' => false],
            'notification_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_type'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'entity_id'         => ['type' => 'BIGINT', 'null' => true],
            'message'           => ['type' => 'TEXT', 'null' => false],
            'action_url'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'data'              => ['type' => 'JSONB', 'null' => true],
            'read_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'dismissed_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('recipient_id');
        $this->forge->addKey('notification_type');
        $this->forge->addKey('read_at');
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->createTable('reach_notifications', true);

        // ── Notification preferences ─────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'user_id'           => ['type' => 'BIGINT', 'null' => false],
            'notification_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'in_app_enabled'    => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
            'email_enabled'     => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'digest_only'       => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['user_id', 'notification_type']);
        $this->forge->addKey('user_id');
        $this->forge->createTable('reach_notification_preferences', true);

        // ── Notification deliveries ──────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'notification_id'   => ['type' => 'BIGINT', 'null' => false],
            'channel'           => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'pending'],
            'sent_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'failed_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'failure_reason'    => ['type' => 'TEXT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('notification_id');
        $this->forge->addKey(['channel', 'status']);
        $this->forge->addForeignKey('notification_id', 'reach_notifications', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_notification_deliveries', true);

        $this->db->query("ALTER TABLE reach_notification_deliveries DROP CONSTRAINT IF EXISTS rnd_channel_chk");
        $this->db->query(
            "ALTER TABLE reach_notification_deliveries ADD CONSTRAINT rnd_channel_chk "
            . "CHECK (channel IN ('in_app','email','sms','push'))"
        );
        $this->db->query("ALTER TABLE reach_notification_deliveries DROP CONSTRAINT IF EXISTS rnd_status_chk");
        $this->db->query(
            "ALTER TABLE reach_notification_deliveries ADD CONSTRAINT rnd_status_chk "
            . "CHECK (status IN ('pending','sent','failed','skipped'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_notification_deliveries', true);
        $this->forge->dropTable('reach_notification_preferences', true);
        $this->forge->dropTable('reach_notifications', true);
    }
}
