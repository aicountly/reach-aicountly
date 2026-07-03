<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachSeoPlans extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'title'               => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'focus_keyword'       => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'secondary_keywords'  => ['type' => 'JSONB', 'null' => true],
            'brief'               => ['type' => 'TEXT', 'null' => true],
            'target_url'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'bot_generated'       => ['type' => 'BOOLEAN', 'default' => false],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('focus_keyword');
        $this->forge->addKey('status');
        $this->forge->createTable('reach_seo_plans', true);

        $this->db->query(
            "ALTER TABLE reach_seo_plans DROP CONSTRAINT IF EXISTS reach_seo_plans_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_seo_plans ADD CONSTRAINT reach_seo_plans_status_check "
            . "CHECK (status IN ('draft','in_progress','completed','archived'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_seo_plans', true);
    }
}
