<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachIndustries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'slug'                => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => false],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'description'         => ['type' => 'TEXT', 'null' => true],
            'parent_id'           => ['type' => 'BIGINT', 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
            'internal_notes'      => ['type' => 'JSONB', 'null' => true],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'updated_by'          => ['type' => 'BIGINT', 'null' => true],
            'reviewed_by'         => ['type' => 'BIGINT', 'null' => true],
            'reviewed_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'         => ['type' => 'BIGINT', 'null' => true],
            'approved_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_actor_type'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'request_id'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'deleted_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->addKey('parent_id');
        $this->forge->createTable('reach_industries', true);

        $this->db->query(
            "ALTER TABLE reach_industries ADD CONSTRAINT reach_industries_no_self_parent_chk "
            . "CHECK (parent_id IS NULL OR parent_id <> id)"
        );
        $this->db->query(
            "ALTER TABLE reach_industries ADD CONSTRAINT reach_industries_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_industries ADD FOREIGN KEY (parent_id) REFERENCES reach_industries(id) ON DELETE SET NULL"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_industries', true);
    }
}
