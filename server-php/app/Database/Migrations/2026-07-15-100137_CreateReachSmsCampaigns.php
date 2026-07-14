<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachSmsCampaigns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGSERIAL'],
            'uuid'                 => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'campaign_id'          => ['type' => 'BIGINT', 'null' => true],
            'tenant_id'            => ['type' => 'BIGINT', 'null' => false],
            'sender_profile_id'    => ['type' => 'BIGINT', 'null' => true],
            'template_version_id'  => ['type' => 'BIGINT', 'null' => true],
            'template_variables'   => ['type' => 'JSONB', 'null' => true],
            'dlt_entity_id'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'dlt_template_id'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'dlt_sender_id'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'provider'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'connection_id'        => ['type' => 'BIGINT', 'null' => true],
            'audience_filter'      => ['type' => 'JSONB', 'null' => true],
            'dispatch_id'          => ['type' => 'BIGINT', 'null' => true],
            'scheduled_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'sent_at'              => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'status'               => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'draft'],
            'stats'                => ['type' => 'JSONB', 'null' => true],
            'created_by'           => ['type' => 'BIGINT', 'null' => true],
            'created_at'           => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'           => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('tenant_id');
        $this->forge->addKey('campaign_id');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_sms_campaigns', true);

        $this->db->query(
            "ALTER TABLE reach_sms_campaigns ADD CONSTRAINT chk_sms_campaigns_status "
            . "CHECK (status IN ('draft','pending_approval','approved','scheduled','sending','sent','failed','archived'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_sms_campaigns', true);
    }
}
