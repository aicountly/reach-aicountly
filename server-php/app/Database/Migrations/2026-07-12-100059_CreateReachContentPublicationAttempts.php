<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Publication attempt placeholders.
 *
 * No real external publishing. Records capture intent, prerequisites, and blockers.
 */
class CreateReachContentPublicationAttempts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                        => ['type' => 'BIGSERIAL'],
            'content_item_id'           => ['type' => 'BIGINT', 'null' => false],
            'publication_target_id'     => ['type' => 'BIGINT', 'null' => false],
            'content_version_id'        => ['type' => 'BIGINT', 'null' => true],
            'status'                    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'prepared'],
            'attempted_at'              => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'blocked_reason'            => ['type' => 'TEXT', 'null' => true],
            'metadata'                  => ['type' => 'JSONB', 'null' => true],
            // Actor
            'initiated_by'              => ['type' => 'BIGINT', 'null' => true],
            'cancelled_by'              => ['type' => 'BIGINT', 'null' => true],
            'cancelled_at'              => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'                => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'                => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('content_item_id');
        $this->forge->addKey('publication_target_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('publication_target_id', 'reach_content_publication_targets', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('content_version_id', 'reach_content_versions', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_content_publication_attempts', true);

        $this->db->query("ALTER TABLE reach_content_publication_attempts DROP CONSTRAINT IF EXISTS rcpa_status_chk");
        $this->db->query(
            "ALTER TABLE reach_content_publication_attempts ADD CONSTRAINT rcpa_status_chk "
            . "CHECK (status IN ('prepared','pending','blocked','cancelled'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_publication_attempts', true);
    }
}
