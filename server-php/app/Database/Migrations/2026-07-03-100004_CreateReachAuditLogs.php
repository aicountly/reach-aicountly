<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachAuditLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'user_id'     => ['type' => 'BIGINT', 'null' => true],
            'action'      => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_id'   => ['type' => 'BIGINT', 'null' => true],
            'old_value'   => ['type' => 'JSONB', 'null' => true],
            'new_value'   => ['type' => 'JSONB', 'null' => true],
            'metadata'    => ['type' => 'JSONB', 'null' => true],
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'user_agent'  => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('action');
        $this->forge->addKey('entity_type');
        $this->forge->addKey('user_id');
        $this->forge->addKey('created_at');
        $this->forge->createTable('reach_audit_logs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_audit_logs', true);
    }
}
