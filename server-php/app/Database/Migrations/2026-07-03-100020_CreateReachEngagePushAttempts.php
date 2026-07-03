<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachEngagePushAttempts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'lead_id'         => ['type' => 'BIGINT', 'null' => false],
            'attempt_number'  => ['type' => 'INTEGER', 'null' => false],
            'request_body'    => ['type' => 'JSONB', 'null' => true],
            'response_status' => ['type' => 'INTEGER', 'null' => true],
            'response_body'   => ['type' => 'JSONB', 'null' => true],
            'error_message'   => ['type' => 'TEXT', 'null' => true],
            'ok'              => ['type' => 'BOOLEAN', 'default' => false],
            'attempted_at'    => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('attempted_at');
        $this->forge->addForeignKey('lead_id', 'reach_leads', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_engage_push_attempts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_engage_push_attempts', true);
    }
}
