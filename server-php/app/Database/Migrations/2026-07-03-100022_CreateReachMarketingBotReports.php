<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachMarketingBotReports extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                     => ['type' => 'BIGSERIAL'],
            'queue_id'               => ['type' => 'BIGINT', 'null' => true],
            'action'                 => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'understanding'          => ['type' => 'TEXT', 'null' => true],
            'data_accessed'          => ['type' => 'JSONB', 'null' => true],
            'content_generated'      => ['type' => 'JSONB', 'null' => true],
            'recommended_action'     => ['type' => 'TEXT', 'null' => true],
            'action_taken'           => ['type' => 'TEXT', 'null' => true],
            'approval_status'        => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'not_required'],
            'publishing_status'      => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'none'],
            'next_recommended_action'=> ['type' => 'TEXT', 'null' => true],
            'mode'                   => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'confirm'],
            'evidence'               => ['type' => 'JSONB', 'null' => true],
            'errors'                 => ['type' => 'JSONB', 'null' => true],
            'created_by'             => ['type' => 'BIGINT', 'null' => true],
            'approved_by'            => ['type' => 'BIGINT', 'null' => true],
            'approved_at'            => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'             => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'             => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('queue_id');
        $this->forge->addKey('action');
        $this->forge->addKey('approval_status');
        $this->forge->addKey('publishing_status');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('queue_id', 'reach_marketing_bot_queue', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_marketing_bot_reports', true);

        $this->db->query(
            "ALTER TABLE reach_marketing_bot_reports DROP CONSTRAINT IF EXISTS reach_mbr_approval_check"
        );
        $this->db->query(
            "ALTER TABLE reach_marketing_bot_reports ADD CONSTRAINT reach_mbr_approval_check "
            . "CHECK (approval_status IN ('not_required','pending','approved','rejected'))"
        );
        $this->db->query(
            "ALTER TABLE reach_marketing_bot_reports DROP CONSTRAINT IF EXISTS reach_mbr_publish_check"
        );
        $this->db->query(
            "ALTER TABLE reach_marketing_bot_reports ADD CONSTRAINT reach_mbr_publish_check "
            . "CHECK (publishing_status IN ('none','pending','queued','published','failed'))"
        );
        $this->db->query(
            "ALTER TABLE reach_marketing_bot_reports DROP CONSTRAINT IF EXISTS reach_mbr_mode_check"
        );
        $this->db->query(
            "ALTER TABLE reach_marketing_bot_reports ADD CONSTRAINT reach_mbr_mode_check "
            . "CHECK (mode IN ('auto','confirm'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_marketing_bot_reports', true);
    }
}
