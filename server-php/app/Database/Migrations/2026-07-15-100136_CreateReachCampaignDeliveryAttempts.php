<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignDeliveryAttempts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'uuid'                => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'dispatch_id'         => ['type' => 'BIGINT', 'null' => false],
            'recipient_id'        => ['type' => 'BIGINT', 'null' => true],
            'attempt_number'      => ['type' => 'INT', 'default' => 1],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'queued'],
            'provider'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'provider_message_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'remote_url'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'failure_class'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'failure_detail'      => ['type' => 'TEXT', 'null' => true],
            'provider_latency_ms' => ['type' => 'INT', 'null' => true],
            'idempotency_key'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'accepted_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'sent_at'             => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'delivered_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'read_at'             => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'failed_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey('dispatch_id');
        $this->forge->addForeignKey('dispatch_id', 'reach_campaign_dispatches', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_delivery_attempts', true);

        $this->db->query(
            "ALTER TABLE reach_campaign_delivery_attempts ADD CONSTRAINT chk_attempts_status "
            . "CHECK (status IN ('queued','sending','accepted','sent','delivered','read','failed','bounced','complained','unsubscribed','suppressed'))"
        );
        $this->db->query(
            "ALTER TABLE reach_campaign_delivery_attempts ADD CONSTRAINT chk_attempts_failure_class "
            . "CHECK (failure_class IS NULL OR failure_class IN ('permanent','transient','rate_limit','rejected','unknown'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_delivery_attempts', true);
    }
}
