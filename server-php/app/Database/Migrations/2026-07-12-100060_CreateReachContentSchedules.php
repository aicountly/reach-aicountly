<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Content scheduling records.
 *
 * Captures when and where content should be published.
 * Actual execution is handled by jobs (Phase 2 = placeholder only).
 */
class CreateReachContentSchedules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                        => ['type' => 'BIGSERIAL'],
            'content_item_id'           => ['type' => 'BIGINT', 'null' => false],
            'publication_target_id'     => ['type' => 'BIGINT', 'null' => false],
            'content_version_id'        => ['type' => 'BIGINT', 'null' => true],
            'scheduled_at'              => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'timezone'                  => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false, 'default' => 'UTC'],
            'schedule_status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'pending'],
            'approval_required'         => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
            'approval_met_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'job_id'                    => ['type' => 'BIGINT', 'null' => true],
            'rescheduled_from_id'       => ['type' => 'BIGINT', 'null' => true],
            'cancelled_at'              => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'cancelled_by'              => ['type' => 'BIGINT', 'null' => true],
            'cancellation_reason'       => ['type' => 'TEXT', 'null' => true],
            // Actor
            'created_by'                => ['type' => 'BIGINT', 'null' => true],
            'updated_by'                => ['type' => 'BIGINT', 'null' => true],
            'created_actor_type'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'request_id'                => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at'                => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'                => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('content_item_id');
        $this->forge->addKey('scheduled_at');
        $this->forge->addKey('schedule_status');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('publication_target_id', 'reach_content_publication_targets', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('content_version_id', 'reach_content_versions', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_content_schedules', true);

        $this->db->query("ALTER TABLE reach_content_schedules DROP CONSTRAINT IF EXISTS rcs_status_chk");
        $this->db->query(
            "ALTER TABLE reach_content_schedules ADD CONSTRAINT rcs_status_chk "
            . "CHECK (schedule_status IN ('pending','approved','ready','executing','completed','cancelled','failed'))"
        );
        $this->db->query("ALTER TABLE reach_content_schedules DROP CONSTRAINT IF EXISTS rcs_actor_type_chk");
        $this->db->query(
            "ALTER TABLE reach_content_schedules ADD CONSTRAINT rcs_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_schedules', true);
    }
}
