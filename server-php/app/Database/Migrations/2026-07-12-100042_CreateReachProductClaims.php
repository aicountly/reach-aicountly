<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachProductClaims extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'product_id'          => ['type' => 'BIGINT', 'null' => false],
            'claim_text'          => ['type' => 'TEXT', 'null' => false],
            'claim_summary'       => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'risk_level'          => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => false, 'default' => 'medium'],
            'requires_evidence'   => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
            'valid_from'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'valid_until'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
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
        $this->forge->addKey('product_id');
        $this->forge->addKey('status');
        $this->forge->addKey('risk_level');
        $this->forge->addKey(['valid_from', 'valid_until']);
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_product_claims', true);

        $this->db->query(
            "ALTER TABLE reach_product_claims ADD CONSTRAINT reach_product_claims_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_product_claims ADD CONSTRAINT reach_product_claims_risk_chk "
            . "CHECK (risk_level IN ('low','medium','high','critical'))"
        );
        $this->db->query(
            "ALTER TABLE reach_product_claims ADD CONSTRAINT reach_product_claims_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_product_claims', true);
    }
}
