<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignOperationalMetrics extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGSERIAL'],
            'dispatch_id'  => ['type' => 'BIGINT', 'null' => false],
            'tenant_id'    => ['type' => 'BIGINT', 'null' => false],
            'channel'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'queued'       => ['type' => 'INT', 'default' => 0],
            'attempted'    => ['type' => 'INT', 'default' => 0],
            'accepted'     => ['type' => 'INT', 'default' => 0],
            'sent'         => ['type' => 'INT', 'default' => 0],
            'delivered'    => ['type' => 'INT', 'default' => 0],
            'read_count'   => ['type' => 'INT', 'default' => 0],
            'failed'       => ['type' => 'INT', 'default' => 0],
            'bounced'      => ['type' => 'INT', 'default' => 0],
            'complained'   => ['type' => 'INT', 'default' => 0],
            'unsubscribed' => ['type' => 'INT', 'default' => 0],
            'suppressed'   => ['type' => 'INT', 'default' => 0],
            'last_updated' => ['type' => 'TIMESTAMPTZ', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('dispatch_id');
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('dispatch_id', 'reach_campaign_dispatches', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_operational_metrics', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_operational_metrics', true);
    }
}
