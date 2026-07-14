<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignAudienceRecipients extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'snapshot_id'           => ['type' => 'BIGINT', 'null' => false],
            'tenant_id'             => ['type' => 'BIGINT', 'null' => false],
            'channel'               => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'channel_address_hash'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'channel_address_masked'=> ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'consent_status'        => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'unknown'],
            'suppressed'            => ['type' => 'BOOLEAN', 'default' => false],
            'suppression_reason'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'eligibility_status'    => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'eligible'],
            'eligibility_reason'    => ['type' => 'TEXT', 'null' => true],
            'dedup_key'             => ['type' => 'VARCHAR', 'constraint' => 256, 'null' => false],
            'created_at'            => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('dedup_key');
        $this->forge->addKey('snapshot_id');
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('snapshot_id', 'reach_campaign_audience_snapshots', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_audience_recipients', true);

        $this->db->query(
            "ALTER TABLE reach_campaign_audience_recipients ADD CONSTRAINT chk_recipients_consent "
            . "CHECK (consent_status IN ('granted','revoked','unknown'))"
        );
        $this->db->query(
            "ALTER TABLE reach_campaign_audience_recipients ADD CONSTRAINT chk_recipients_eligibility "
            . "CHECK (eligibility_status IN ('eligible','ineligible','suppressed','no_consent','invalid_address','duplicate'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_audience_recipients', true);
    }
}
