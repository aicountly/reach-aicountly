<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachBrandRules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'product_id'          => ['type' => 'BIGINT', 'null' => true],
            'rule_type'           => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false, 'default' => 'tone'],
            'rule_text'           => ['type' => 'TEXT', 'null' => false],
            'applies_to'          => ['type' => 'JSONB', 'null' => true],
            'is_mandatory'        => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
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
        $this->forge->addKey('product_id');
        $this->forge->addKey('status');
        $this->forge->addKey('rule_type');
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_brand_rules', true);

        $this->db->query(
            "ALTER TABLE reach_brand_rules ADD CONSTRAINT reach_brand_rules_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_brand_rules ADD CONSTRAINT reach_brand_rules_type_chk "
            . "CHECK (rule_type IN ('preferred_name','avoid_term','tone','trademark','competitor_mention'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_brand_rules', true);
    }
}
