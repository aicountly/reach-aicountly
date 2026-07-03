<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachRoles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'description' => ['type' => 'TEXT', 'null' => true],
            'permissions' => ['type' => 'JSONB', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('reach_roles', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_roles', true);
    }
}
