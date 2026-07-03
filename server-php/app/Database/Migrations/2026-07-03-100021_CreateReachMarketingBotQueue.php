<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachMarketingBotQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGSERIAL'],
            'action'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'payload'        => ['type' => 'JSONB', 'null' => true],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'queued'],
            'result_summary' => ['type' => 'JSONB', 'null' => true],
            'requested_by'   => ['type' => 'BIGINT', 'null' => true],
            'started_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'finished_at'    => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'error_message'  => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'     => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('action');
        $this->forge->addKey('status');
        $this->forge->addKey('created_at');
        $this->forge->createTable('reach_marketing_bot_queue', true);

        $this->db->query(
            "ALTER TABLE reach_marketing_bot_queue DROP CONSTRAINT IF EXISTS reach_mbq_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_marketing_bot_queue ADD CONSTRAINT reach_mbq_status_check "
            . "CHECK (status IN ('queued','running','completed','failed','cancelled'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_marketing_bot_queue', true);
    }
}
