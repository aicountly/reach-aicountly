<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachPublicationWebhookEvents extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_publication_webhook_events (
                id                  BIGSERIAL PRIMARY KEY,
                connection_id       BIGINT REFERENCES reach_publication_connections(id) ON DELETE SET NULL,
                deployment_id       BIGINT REFERENCES reach_publication_deployments(id) ON DELETE SET NULL,
                event_type          VARCHAR(64) NOT NULL,
                public_content_id   BIGINT,
                payload_json        JSONB NOT NULL DEFAULT '{}'::jsonb,
                status              VARCHAR(32) NOT NULL DEFAULT 'received'
                                    CHECK (status IN ('received','processed','ignored','error')),
                processing_error    TEXT,
                received_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                processed_at        TIMESTAMPTZ
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_webhook_events_deployment ON reach_publication_webhook_events(deployment_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_webhook_events_status ON reach_publication_webhook_events(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_webhook_events_received ON reach_publication_webhook_events(received_at DESC)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_publication_webhook_events CASCADE');
    }
}
