<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Immutable content version history.
 *
 * Records are never updated once created. The current version is tracked via
 * reach_content_items.current_version_id (updated atomically in a transaction).
 */
class CreateReachContentVersions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'content_item_id'       => ['type' => 'BIGINT', 'null' => false],
            'version_number'        => ['type' => 'INT', 'null' => false, 'default' => 1],
            'title'                 => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false],
            'summary'               => ['type' => 'TEXT', 'null' => true],
            'body_html'             => ['type' => 'TEXT', 'null' => true],
            'body_markdown'         => ['type' => 'TEXT', 'null' => true],
            'body_plain_text'       => ['type' => 'TEXT', 'null' => true],
            'structured_payload'    => ['type' => 'JSONB', 'null' => true],
            'change_summary'        => ['type' => 'TEXT', 'null' => true],
            'is_current'            => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            // Actor
            'created_actor_type'    => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'created_by_user_id'    => ['type' => 'BIGINT', 'null' => true],
            'created_by_service'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'source_generation_id'  => ['type' => 'BIGINT', 'null' => true],
            'request_id'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['content_item_id', 'version_number']);
        $this->forge->addKey('content_item_id');
        $this->forge->addKey('is_current');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_versions', true);

        $this->db->query("ALTER TABLE reach_content_versions DROP CONSTRAINT IF EXISTS rcv_actor_type_chk");
        $this->db->query(
            "ALTER TABLE reach_content_versions ADD CONSTRAINT rcv_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );

        // Partial unique index: at most one current version per content item
        $this->db->query(
            "CREATE UNIQUE INDEX IF NOT EXISTS rcv_one_current_per_item "
            . "ON reach_content_versions (content_item_id) WHERE is_current = TRUE"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_versions', true);
    }
}
