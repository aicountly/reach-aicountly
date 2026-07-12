<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachEvidence extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'slug'                => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'title'               => ['type' => 'VARCHAR', 'constraint' => 400, 'null' => false],
            'summary'             => ['type' => 'TEXT', 'null' => true],
            'evidence_type'       => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false, 'default' => 'internal'],
            'source_id'           => ['type' => 'BIGINT', 'null' => true],
            'external_url'        => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
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
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->addKey('evidence_type');
        $this->forge->addKey('source_id');
        $this->forge->addKey(['valid_from', 'valid_until']);
        $this->forge->addForeignKey('source_id', 'reach_sources', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_evidence', true);

        $this->db->query(
            "ALTER TABLE reach_evidence ADD CONSTRAINT reach_evidence_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_evidence ADD CONSTRAINT reach_evidence_type_chk "
            . "CHECK (evidence_type IN ('benchmark','case_study','whitepaper','demo','changelog','support_article','third_party_report','internal'))"
        );
        $this->db->query(
            "ALTER TABLE reach_evidence ADD CONSTRAINT reach_evidence_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_evidence', true);
    }
}
