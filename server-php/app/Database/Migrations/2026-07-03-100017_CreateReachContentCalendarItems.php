<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachContentCalendarItems extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'date'        => ['type' => 'DATE', 'null' => false],
            'item_kind'   => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false],
            'ref_type'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'ref_id'      => ['type' => 'BIGINT', 'null' => true],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'notes'       => ['type' => 'TEXT', 'null' => true],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('date');
        $this->forge->addKey(['ref_type', 'ref_id']);
        $this->forge->createTable('reach_content_calendar_items', true);

        $this->db->query(
            "ALTER TABLE reach_content_calendar_items DROP CONSTRAINT IF EXISTS reach_ccal_kind_check"
        );
        $this->db->query(
            "ALTER TABLE reach_content_calendar_items ADD CONSTRAINT reach_ccal_kind_check "
            . "CHECK (item_kind IN ('blog','social','email','whatsapp','campaign','webinar','other'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_calendar_items', true);
    }
}
