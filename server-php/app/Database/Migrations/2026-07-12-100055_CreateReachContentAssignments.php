<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Editorial assignments for content items.
 *
 * Tracks who is responsible for what role on a content item.
 * A user may hold one role per content item (unique on content_item_id + role + user_id).
 */
class CreateReachContentAssignments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'user_id'           => ['type' => 'BIGINT', 'null' => false],
            'role'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'assigned_at'       => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'unassigned_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'due_date'          => ['type' => 'DATE', 'null' => true],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            // Actor
            'assigned_by'       => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['content_item_id', 'user_id', 'role']);
        $this->forge->addKey('content_item_id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('role');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_assignments', true);

        $this->db->query("ALTER TABLE reach_content_assignments DROP CONSTRAINT IF EXISTS rca_role_chk");
        $this->db->query(
            "ALTER TABLE reach_content_assignments ADD CONSTRAINT rca_role_chk "
            . "CHECK (role IN ("
            . "'owner','writer','reviewer','subject_matter_reviewer',"
            . "'compliance_reviewer','publisher','observer'"
            . "))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_assignments', true);
    }
}
