<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityEngagementEvents extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_engagement_events (
                id                  BIGSERIAL PRIMARY KEY,
                uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                event_type          VARCHAR(40) NOT NULL
                                        CHECK (event_type IN (
                                            'page_view','helpful','not_helpful',
                                            'reply','report','click'
                                        )),
                answer_id           BIGINT REFERENCES reach_community_official_answers(id) ON DELETE SET NULL,
                question_id         BIGINT REFERENCES reach_community_questions(id) ON DELETE SET NULL,
                source              VARCHAR(40) NOT NULL DEFAULT 'public_site',
                event_timestamp     TIMESTAMPTZ NOT NULL,
                deduplication_key   VARCHAR(255) NOT NULL UNIQUE,
                session_reference   VARCHAR(255),
                bot_filtered        BOOLEAN NOT NULL DEFAULT FALSE,
                validated           BOOLEAN NOT NULL DEFAULT FALSE,
                ingested_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcee_answer ON reach_community_engagement_events(answer_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcee_type ON reach_community_engagement_events(event_type)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcee_ts ON reach_community_engagement_events(event_timestamp)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcee_validated ON reach_community_engagement_events(validated)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_engagement_events CASCADE');
    }
}
