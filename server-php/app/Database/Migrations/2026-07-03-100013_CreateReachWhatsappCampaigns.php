<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachWhatsappCampaigns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'campaign_id'     => ['type' => 'BIGINT', 'null' => true],
            'template_name'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'template_params' => ['type' => 'JSONB', 'null' => true],
            'audience_filter' => ['type' => 'JSONB', 'null' => true],
            'scheduled_at'    => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'sent_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'stats'           => ['type' => 'JSONB', 'null' => true],
            'created_by'      => ['type' => 'BIGINT', 'null' => true],
            'created_at'      => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'      => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_whatsapp_campaigns', true);

        $this->db->query(
            "ALTER TABLE reach_whatsapp_campaigns DROP CONSTRAINT IF EXISTS reach_whatsapp_campaigns_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_whatsapp_campaigns ADD CONSTRAINT reach_whatsapp_campaigns_status_check "
            . "CHECK (status IN ('draft','pending_approval','approved','scheduled','sending','sent','failed','archived'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_whatsapp_campaigns', true);
    }
}
