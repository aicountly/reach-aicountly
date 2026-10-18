<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The social inbox bot (stream a, 2026-10-18): every comment / message Reach
 * read from social-api.ai, what Gemini classified it as, and what Reach did —
 * replied (only once social-api.ai accepted the reply), lodged a Flow ticket,
 * recorded a lead, hid / ignored it, or failed, always with the reason.
 *
 * One row per (network, external_id): polling twice never stores a message
 * twice. docs/operations/SOCIAL_API.md → Social inbox bot.
 */
class CreateReachSocialInbox extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS reach_social_inbox (
            id                  BIGSERIAL PRIMARY KEY,
            network             VARCHAR(32)  NOT NULL,
            account_id          VARCHAR(191) NULL,
            external_id         VARCHAR(191) NOT NULL,
            kind                VARCHAR(16)  NOT NULL DEFAULT 'comment'
                                CHECK (kind IN ('comment','message','mention','review','other')),
            thread_id           VARCHAR(191) NULL,
            parent_id           VARCHAR(191) NULL,
            author_name         VARCHAR(200) NULL,
            author_handle       VARCHAR(200) NULL,
            author_external_id  VARCHAR(191) NULL,
            text                TEXT         NOT NULL,
            post_url            TEXT         NULL,
            received_at         TIMESTAMPTZ  NULL,
            fetched_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            raw_json            JSONB        NULL,
            status              VARCHAR(16)  NOT NULL DEFAULT 'new'
                                CHECK (status IN ('new','retry','deferred','replied','ticket_created','lead_created','ignored','hidden','failed')),
            status_reason       TEXT         NULL,
            attempts            INT          NOT NULL DEFAULT 0,
            last_error          TEXT         NULL,
            classification      VARCHAR(16)  NULL
                                CHECK (classification IS NULL OR classification IN ('question','praise','complaint','sales_lead','spam_abuse','sensitive','other')),
            confidence          SMALLINT     NULL,
            severity            VARCHAR(8)   NULL CHECK (severity IS NULL OR severity IN ('low','medium','high')),
            severity_reason     VARCHAR(300) NULL,
            personal_data       BOOLEAN      NOT NULL DEFAULT FALSE,
            summary             VARCHAR(500) NULL,
            classifier_model    VARCHAR(120) NULL,
            classified_at       TIMESTAMPTZ  NULL,
            reply_draft         TEXT         NULL,
            reply_text          TEXT         NULL,
            reply_status        VARCHAR(16)  NOT NULL DEFAULT 'none'
                                CHECK (reply_status IN ('none','pending','sent','failed','skipped')),
            reply_reason        TEXT         NULL,
            reply_external_id   VARCHAR(191) NULL,
            replied_at          TIMESTAMPTZ  NULL,
            vetting_decision    VARCHAR(16)  NULL,
            vetting_run_id      BIGINT       NULL REFERENCES reach_ai_vetting_runs(id) ON DELETE SET NULL,
            vetting_reasons_json JSONB       NOT NULL DEFAULT '[]'::jsonb,
            ticket_number       VARCHAR(40)  NULL,
            ticket_id           BIGINT       NULL,
            ticket_error        TEXT         NULL,
            lead_id             BIGINT       NULL REFERENCES reach_leads(id) ON DELETE SET NULL,
            owner_alerted_at    TIMESTAMPTZ  NULL,
            created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT reach_social_inbox_message_key UNIQUE (network, external_id)
        )");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_social_inbox_status ON reach_social_inbox (status, received_at)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_social_inbox_thread ON reach_social_inbox (network, thread_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_social_inbox_account ON reach_social_inbox (network, account_id, received_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_social_inbox_replied ON reach_social_inbox (replied_at) WHERE replied_at IS NOT NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_social_inbox');
    }
}
