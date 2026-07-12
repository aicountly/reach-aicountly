<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Publication channel target definitions.
 *
 * These are system-level records defining where content can be published.
 * No actual publishing occurs in Phase 2 — attempts are placeholder records only.
 */
class CreateReachContentPublicationTargets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGSERIAL'],
            'uuid'          => ['type' => 'UUID', 'null' => false, 'default' => new RawSql('gen_random_uuid()')],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'channel'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'target_url'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'target_config' => ['type' => 'JSONB', 'null' => true],
            'is_active'     => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
            'notes'         => ['type' => 'TEXT', 'null' => true],
            // Actor
            'created_by'    => ['type' => 'BIGINT', 'null' => true],
            'updated_by'    => ['type' => 'BIGINT', 'null' => true],
            'deleted_at'    => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'    => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'    => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('channel');
        $this->forge->addKey('is_active');
        $this->forge->createTable('reach_content_publication_targets', true);

        $this->db->query("ALTER TABLE reach_content_publication_targets DROP CONSTRAINT IF EXISTS rcpt_channel_chk");
        $this->db->query(
            "ALTER TABLE reach_content_publication_targets ADD CONSTRAINT rcpt_channel_chk "
            . "CHECK (channel IN ("
            . "'aicountly_website','youtube','linkedin','twitter','facebook','instagram',"
            . "'whatsapp_broadcast','sms_broadcast','email_campaign','help_centre',"
            . "'community_forum','press_release','partner_portal'"
            . "))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_publication_targets', true);
    }
}
