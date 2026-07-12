<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachProducts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'slug'                => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'short_description'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'description'         => ['type' => 'TEXT', 'null' => true],
            'public_url'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
            'legacy_code_path'    => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'internal_notes'      => ['type' => 'JSONB', 'null' => true],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'updated_by'          => ['type' => 'BIGINT', 'null' => true],
            'reviewed_by'         => ['type' => 'BIGINT', 'null' => true],
            'reviewed_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'         => ['type' => 'BIGINT', 'null' => true],
            'approved_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'review_due_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'next_review_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
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
        $this->forge->addKey('status');
        $this->forge->addKey('deleted_at');
        $this->forge->createTable('reach_products', true);

        $this->db->query(
            "ALTER TABLE reach_products DROP CONSTRAINT IF EXISTS reach_products_status_chk"
        );
        $this->db->query(
            "ALTER TABLE reach_products ADD CONSTRAINT reach_products_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_products DROP CONSTRAINT IF EXISTS reach_products_actor_type_chk"
        );
        $this->db->query(
            "ALTER TABLE reach_products ADD CONSTRAINT reach_products_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_products', true);
    }
}
