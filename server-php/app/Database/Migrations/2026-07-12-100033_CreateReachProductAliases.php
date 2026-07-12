<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachProductAliases extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'product_id' => ['type' => 'BIGINT', 'null' => false],
            'alias'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'source'     => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false, 'default' => 'user_defined'],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['product_id', 'alias']);
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_product_aliases', true);

        $this->db->query(
            "ALTER TABLE reach_product_aliases DROP CONSTRAINT IF EXISTS reach_product_aliases_source_chk"
        );
        $this->db->query(
            "ALTER TABLE reach_product_aliases ADD CONSTRAINT reach_product_aliases_source_chk "
            . "CHECK (source IN ('legacy_code','user_defined','brand'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_product_aliases', true);
    }
}
