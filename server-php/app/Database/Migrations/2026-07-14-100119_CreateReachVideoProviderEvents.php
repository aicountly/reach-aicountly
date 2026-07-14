<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoProviderEvents extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_provider_events (
                id                  BIGSERIAL    PRIMARY KEY,
                provider            VARCHAR(64)  NOT NULL,
                provider_event_id   VARCHAR(255) NOT NULL,
                event_type          VARCHAR(64),
                payload_hash        VARCHAR(64),
                received_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                UNIQUE (provider, provider_event_id)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvpe_provider   ON reach_video_provider_events(provider)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvpe_received   ON reach_video_provider_events(received_at DESC)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_provider_events CASCADE');
    }
}
