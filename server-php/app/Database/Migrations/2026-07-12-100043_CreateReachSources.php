<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Sources must be created before Evidence (Evidence has optional source_id FK).
 */
class CreateReachSources extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'slug'                => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'url'                 => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'source_type'         => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false, 'default' => 'internal'],
            'authority_score'     => ['type' => 'INTEGER', 'null' => true],
            'description'         => ['type' => 'TEXT', 'null' => true],
            'is_active'           => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
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
            'request_id'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'deleted_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->addKey('source_type');
        $this->forge->createTable('reach_sources', true);

        $this->db->query(
            "ALTER TABLE reach_sources ADD CONSTRAINT reach_sources_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_sources ADD CONSTRAINT reach_sources_type_chk "
            . "CHECK (source_type IN ('official_docs','press_release','third_party','community','internal'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_sources', true);
    }
}
