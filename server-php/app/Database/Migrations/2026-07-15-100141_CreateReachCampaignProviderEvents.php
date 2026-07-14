<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignProviderEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'uuid'                => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'tenant_id'           => ['type' => 'BIGINT', 'null' => false],
            'dispatch_id'         => ['type' => 'BIGINT', 'null' => true],
            'attempt_id'          => ['type' => 'BIGINT', 'null' => true],
            'provider'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'connection_id'       => ['type' => 'BIGINT', 'null' => true],
            'event_type'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'raw_event'           => ['type' => 'JSONB', 'null' => true],
            'normalised_status'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'provider_event_id'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'received_at'         => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'processed_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('dispatch_id');
        $this->forge->addKey('attempt_id');
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('reach_campaign_provider_events', true);

        // Deduplication index — prevents replay
        $this->db->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_campaign_provider_event_id '
            . 'ON reach_campaign_provider_events(provider, connection_id, provider_event_id) '
            . 'WHERE provider_event_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_provider_events', true);
    }
}
