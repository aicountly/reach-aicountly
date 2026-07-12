<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCitations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'source_id'       => ['type' => 'BIGINT', 'null' => false],
            'evidence_id'     => ['type' => 'BIGINT', 'null' => true],
            'citation_text'   => ['type' => 'TEXT', 'null' => true],
            'page_reference'  => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'accessed_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
            'created_by'      => ['type' => 'BIGINT', 'null' => true],
            'updated_by'      => ['type' => 'BIGINT', 'null' => true],
            'reviewed_by'     => ['type' => 'BIGINT', 'null' => true],
            'reviewed_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'     => ['type' => 'BIGINT', 'null' => true],
            'approved_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'request_id'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'deleted_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'      => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'      => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('source_id');
        $this->forge->addKey('evidence_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('source_id', 'reach_sources', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('evidence_id', 'reach_evidence', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_citations', true);

        $this->db->query(
            "ALTER TABLE reach_citations ADD CONSTRAINT reach_citations_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_citations', true);
    }
}
