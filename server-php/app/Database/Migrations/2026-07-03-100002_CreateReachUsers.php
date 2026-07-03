<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'role_id'         => ['type' => 'BIGINT', 'null' => false],
            'is_active'       => ['type' => 'BOOLEAN', 'default' => true],
            'last_login_at'   => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'last_login_ip'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'failed_attempts' => ['type' => 'INTEGER', 'default' => 0],
            'created_at'      => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'      => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->addForeignKey('role_id', 'reach_roles', 'id', '', 'RESTRICT');
        $this->forge->createTable('reach_users', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_users', true);
    }
}
