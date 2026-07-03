<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachWorkerHealthSnapshots extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGSERIAL'],
            'checked_at'    => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'ok'            => ['type' => 'BOOLEAN', 'default' => false],
            'http_status'   => ['type' => 'INTEGER', 'null' => true],
            'latency_ms'    => ['type' => 'INTEGER', 'null' => true],
            'response'      => ['type' => 'JSONB', 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('checked_at');
        $this->forge->addKey('ok');
        $this->forge->createTable('reach_worker_health_snapshots', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_worker_health_snapshots', true);
    }
}
