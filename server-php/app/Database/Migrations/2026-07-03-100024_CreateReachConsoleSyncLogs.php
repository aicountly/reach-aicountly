<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachConsoleSyncLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'event_type'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'payload'         => ['type' => 'JSONB', 'null' => true],
            'response_status' => ['type' => 'INTEGER', 'null' => true],
            'response_body'   => ['type' => 'JSONB', 'null' => true],
            'ok'              => ['type' => 'BOOLEAN', 'default' => false],
            'error_message'   => ['type' => 'TEXT', 'null' => true],
            'attempted_at'    => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('event_type');
        $this->forge->addKey('ok');
        $this->forge->addKey('attempted_at');
        $this->forge->createTable('reach_console_sync_logs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_console_sync_logs', true);
    }
}
