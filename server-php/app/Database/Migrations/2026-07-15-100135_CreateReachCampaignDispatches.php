<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignDispatches extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'uuid'                => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'campaign_id'         => ['type' => 'BIGINT', 'null' => false],
            'campaign_version_id' => ['type' => 'BIGINT', 'null' => true],
            'snapshot_id'         => ['type' => 'BIGINT', 'null' => true],
            'tenant_id'           => ['type' => 'BIGINT', 'null' => false],
            'channel'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'queued'],
            'connection_id'       => ['type' => 'BIGINT', 'null' => true],
            'idempotency_key'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'scheduled_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'started_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'completed_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'total_recipients'    => ['type' => 'INT', 'default' => 0],
            'sent_count'          => ['type' => 'INT', 'default' => 0],
            'failed_count'        => ['type' => 'INT', 'default' => 0],
            'suppressed_count'    => ['type' => 'INT', 'default' => 0],
            'lock_version'        => ['type' => 'INT', 'default' => 0],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_dispatches', true);

        $this->db->query(
            "ALTER TABLE reach_campaign_dispatches ADD CONSTRAINT chk_dispatches_status "
            . "CHECK (status IN ('queued','dispatching','paused','cancelled','partially_completed','completed','failed','dead_lettered'))"
        );
        $this->db->query(
            "ALTER TABLE reach_campaign_dispatches ADD CONSTRAINT chk_dispatches_channel "
            . "CHECK (channel IN ('social','email','whatsapp','sms'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_dispatches', true);
    }
}
