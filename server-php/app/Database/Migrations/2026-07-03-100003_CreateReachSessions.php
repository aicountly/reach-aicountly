<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachSessions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'user_id'    => ['type' => 'BIGINT', 'null' => false],
            'token_hash' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'expires_at' => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'revoked_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('user_id');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('user_id', 'reach_users', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_sessions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_sessions', true);
    }
}
