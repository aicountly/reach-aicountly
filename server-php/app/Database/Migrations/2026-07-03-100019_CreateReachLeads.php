<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachLeads extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGSERIAL'],
            'name'                 => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'email'                => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'mobile'               => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'whatsapp'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'organization'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'source_kind'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'campaign_id'          => ['type' => 'BIGINT', 'null' => true],
            'landing_page_id'      => ['type' => 'BIGINT', 'null' => true],
            'product_interest'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'priority'             => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'normal'],
            'notes'                => ['type' => 'TEXT', 'null' => true],
            'raw_payload'          => ['type' => 'JSONB', 'null' => true],
            'engage_push_status'   => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending_push'],
            'engage_lead_code'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'engage_push_attempts' => ['type' => 'INTEGER', 'default' => 0],
            'last_push_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'last_push_error'      => ['type' => 'TEXT', 'null' => true],
            'created_by'           => ['type' => 'BIGINT', 'null' => true],
            'created_at'           => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'           => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('email');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('landing_page_id');
        $this->forge->addKey('engage_push_status');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'SET NULL');
        $this->forge->addForeignKey('landing_page_id', 'reach_landing_pages', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_leads', true);

        $this->db->query(
            "ALTER TABLE reach_leads DROP CONSTRAINT IF EXISTS reach_leads_push_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_leads ADD CONSTRAINT reach_leads_push_status_check "
            . "CHECK (engage_push_status IN ('pending_push','pushed','failed','duplicate','rejected','retry_scheduled'))"
        );
        $this->db->query(
            "ALTER TABLE reach_leads DROP CONSTRAINT IF EXISTS reach_leads_priority_check"
        );
        $this->db->query(
            "ALTER TABLE reach_leads ADD CONSTRAINT reach_leads_priority_check "
            . "CHECK (priority IN ('low','normal','high','urgent'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_leads', true);
    }
}
