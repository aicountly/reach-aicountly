<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachBotSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGSERIAL'],
            'mode'                 => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'confirm'],
            'allowed_auto_actions' => ['type' => 'JSONB', 'null' => true],
            'updated_by'           => ['type' => 'BIGINT', 'null' => true],
            'created_at'           => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'           => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('reach_bot_settings', true);

        // Enforce that `mode` is only ever 'auto' or 'confirm'.
        $this->db->query(
            "ALTER TABLE reach_bot_settings DROP CONSTRAINT IF EXISTS reach_bot_settings_mode_check"
        );
        $this->db->query(
            "ALTER TABLE reach_bot_settings ADD CONSTRAINT reach_bot_settings_mode_check "
            . "CHECK (mode IN ('auto', 'confirm'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_bot_settings', true);
    }
}
