<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'key'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'value_json'  => ['type' => 'JSONB', 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'updated_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('key');
        $this->forge->createTable('reach_settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_settings', true);
    }
}
