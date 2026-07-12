<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Threaded editorial comments on content items.
 *
 * Internal-only flag ensures reviewer discussions are not exposed publicly.
 * Comments are linked to a specific version so diffs can show context.
 */
class CreateReachContentComments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'version_id'        => ['type' => 'BIGINT', 'null' => true],
            'parent_comment_id' => ['type' => 'BIGINT', 'null' => true],
            'body'              => ['type' => 'TEXT', 'null' => false],
            'internal_only'     => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
            'resolved_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'resolved_by'       => ['type' => 'BIGINT', 'null' => true],
            // Actor
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_actor_type' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'deleted_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('content_item_id');
        $this->forge->addKey('parent_comment_id');
        $this->forge->addKey('version_id');
        $this->forge->addKey('resolved_at');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('version_id', 'reach_content_versions', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_content_comments', true);

        $this->db->query("ALTER TABLE reach_content_comments DROP CONSTRAINT IF EXISTS rcc_actor_type_chk");
        $this->db->query(
            "ALTER TABLE reach_content_comments ADD CONSTRAINT rcc_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_comments', true);
    }
}
