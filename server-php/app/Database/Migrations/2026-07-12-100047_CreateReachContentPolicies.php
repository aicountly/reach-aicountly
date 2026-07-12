<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachContentPolicies extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'name'                  => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'policy_type'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'brand'],
            'policy_text'           => ['type' => 'TEXT', 'null' => false],
            'applies_to_channels'   => ['type' => 'JSONB', 'null' => true],
            'is_mandatory'          => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
            'internal_notes'        => ['type' => 'JSONB', 'null' => true],
            'created_by'            => ['type' => 'BIGINT', 'null' => true],
            'updated_by'            => ['type' => 'BIGINT', 'null' => true],
            'reviewed_by'           => ['type' => 'BIGINT', 'null' => true],
            'reviewed_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'           => ['type' => 'BIGINT', 'null' => true],
            'approved_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_actor_type'    => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'request_id'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'deleted_at'            => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'            => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status');
        $this->forge->addKey('policy_type');
        $this->forge->createTable('reach_content_policies', true);

        $this->db->query(
            "ALTER TABLE reach_content_policies ADD CONSTRAINT reach_content_policies_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_content_policies ADD CONSTRAINT reach_content_policies_type_chk "
            . "CHECK (policy_type IN ('legal','brand','accuracy','format','channel'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_policies', true);
    }
}
