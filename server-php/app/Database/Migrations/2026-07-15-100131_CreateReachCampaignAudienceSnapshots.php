<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignAudienceSnapshots extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'uuid'                => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'campaign_id'         => ['type' => 'BIGINT', 'null' => false],
            'campaign_version_id' => ['type' => 'BIGINT', 'null' => true],
            'tenant_id'           => ['type' => 'BIGINT', 'null' => false],
            'channel'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'recipient_count'     => ['type' => 'INT', 'default' => 0],
            'eligible_count'      => ['type' => 'INT', 'default' => 0],
            'suppressed_count'    => ['type' => 'INT', 'default' => 0],
            'snapshot_criteria'   => ['type' => 'JSONB', 'null' => true],
            'frozen_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'frozen_by'           => ['type' => 'BIGINT', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_audience_snapshots', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_audience_snapshots', true);
    }
}
