<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaigns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGSERIAL'],
            'name'               => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'campaign_type'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'objective'          => ['type' => 'TEXT', 'null' => true],
            'target_audience'    => ['type' => 'JSONB', 'null' => true],
            'products_promoted'  => ['type' => 'JSONB', 'null' => true],
            'budget_amount'      => ['type' => 'NUMERIC', 'constraint' => '18,2', 'null' => true],
            'currency'           => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'start_date'         => ['type' => 'DATE', 'null' => true],
            'end_date'           => ['type' => 'DATE', 'null' => true],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'channels'           => ['type' => 'JSONB', 'null' => true],
            'utm_source'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'utm_medium'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'utm_campaign'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'utm_content'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'utm_term'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'landing_page_url'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'creative_copy'      => ['type' => 'TEXT', 'null' => true],
            'approval_status'    => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'not_required'],
            'approved_by'        => ['type' => 'BIGINT', 'null' => true],
            'approved_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'analytics_summary'  => ['type' => 'JSONB', 'null' => true],
            'leads_generated'    => ['type' => 'INTEGER', 'default' => 0],
            'bot_generated'      => ['type' => 'BOOLEAN', 'default' => false],
            'created_by'         => ['type' => 'BIGINT', 'null' => true],
            'created_at'         => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'         => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('campaign_type');
        $this->forge->addKey('status');
        $this->forge->addKey('approval_status');
        $this->forge->addKey('start_date');
        $this->forge->addKey('end_date');
        $this->forge->createTable('reach_campaigns', true);

        $this->db->query(
            "ALTER TABLE reach_campaigns DROP CONSTRAINT IF EXISTS reach_campaigns_type_check"
        );
        $this->db->query(
            "ALTER TABLE reach_campaigns ADD CONSTRAINT reach_campaigns_type_check "
            . "CHECK (campaign_type IN ('email','whatsapp','social','landing','paid_ad','webinar','referral','multi'))"
        );
        $this->db->query(
            "ALTER TABLE reach_campaigns DROP CONSTRAINT IF EXISTS reach_campaigns_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_campaigns ADD CONSTRAINT reach_campaigns_status_check "
            . "CHECK (status IN ('draft','pending_approval','approved','running','paused','completed','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_campaigns DROP CONSTRAINT IF EXISTS reach_campaigns_approval_check"
        );
        $this->db->query(
            "ALTER TABLE reach_campaigns ADD CONSTRAINT reach_campaigns_approval_check "
            . "CHECK (approval_status IN ('not_required','pending','approved','rejected'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaigns', true);
    }
}
