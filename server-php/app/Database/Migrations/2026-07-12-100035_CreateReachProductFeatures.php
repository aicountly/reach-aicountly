<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachProductFeatures extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'module_id'           => ['type' => 'BIGINT', 'null' => false],
            'slug'                => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'description'         => ['type' => 'TEXT', 'null' => true],
            'availability'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'unknown'],
            'availability_notes'  => ['type' => 'TEXT', 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
            'sort_order'          => ['type' => 'INTEGER', 'null' => false, 'default' => 0],
            'internal_notes'      => ['type' => 'JSONB', 'null' => true],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'updated_by'          => ['type' => 'BIGINT', 'null' => true],
            'reviewed_by'         => ['type' => 'BIGINT', 'null' => true],
            'reviewed_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'         => ['type' => 'BIGINT', 'null' => true],
            'approved_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_actor_type'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'created_by_service'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'generation_job_id'   => ['type' => 'BIGINT', 'null' => true],
            'request_id'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'deleted_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('module_id');
        $this->forge->addKey('status');
        $this->forge->addKey('availability');
        $this->forge->addForeignKey('module_id', 'reach_product_modules', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_product_features', true);

        $this->db->query(
            "ALTER TABLE reach_product_features ADD CONSTRAINT reach_product_features_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_product_features ADD CONSTRAINT reach_product_features_availability_chk "
            . "CHECK (availability IN ('available','limited','beta','planned','deprecated','unknown'))"
        );
        $this->db->query(
            "ALTER TABLE reach_product_features ADD CONSTRAINT reach_product_features_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_product_features', true);
    }
}
