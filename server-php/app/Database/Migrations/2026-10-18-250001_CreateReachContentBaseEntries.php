<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The living half of the content base moves out of git into the database.
 *
 * Until now every content-base topic, its "status" and the next topics lived
 * only in server-php/content-base/*.json. The files are rsynced with
 * `--delete` on every deploy and the deploy is started by hand, so nothing a
 * routine learned (a new topic from a product change, "this one is done")
 * could ever reach the server without a person committing, pushing and
 * deploying. The base stalled on its 05-08-2026 seed.
 *
 * reach_content_base_entries — one row per topic, per content type:
 *   origin  seed     imported from the git files (the strategy baseline)
 *           routine  proposed by a Claude routine via v1/automation/content-base/propose
 *   status  planned  waiting to be written
 *           queued   a routine submitted it; Reach is vetting it
 *           produced vetted and approved; Reach is publishing it
 *           published live on aicountly.com
 *           retired  dropped (seed retired in git, rejected twice, or the
 *                    routine found the feature does not exist)
 *   Status is reconciled from the real pipeline tables, never taken on a
 *   routine's word.
 *
 * reach_routine_activity — last time a routine touched Reach for each content
 * type and endpoint (written by AutomationTokenFilter::after), so the console
 * and the daily report can say "no routine activity for 40 h" instead of
 * guessing from an unchanged table.
 */
class CreateReachContentBaseEntries extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_base_entries (
                id               BIGSERIAL PRIMARY KEY,
                content_type     VARCHAR(32)  NOT NULL CHECK (content_type IN ('blog','knowledge_base','community')),
                entry_key        VARCHAR(120) NOT NULL,
                title            VARCHAR(500) NOT NULL,
                product_slug     VARCHAR(100) NULL,
                category         VARCHAR(120) NULL,
                brief            TEXT         NULL,
                payload_json     JSONB        NOT NULL DEFAULT '{}'::jsonb,
                target_date      DATE         NULL,
                priority         INTEGER      NOT NULL DEFAULT 0,
                origin           VARCHAR(16)  NOT NULL DEFAULT 'seed' CHECK (origin IN ('seed','routine')),
                status           VARCHAR(16)  NOT NULL DEFAULT 'planned'
                                 CHECK (status IN ('planned','queued','produced','published','retired')),
                status_reason    TEXT         NULL,
                content_key      VARCHAR(120) NULL,
                content_item_id  BIGINT       NULL,
                community_question_id BIGINT  NULL,
                attempts         INTEGER      NOT NULL DEFAULT 0,
                evidence_json    JSONB        NOT NULL DEFAULT '[]'::jsonb,
                proposed_by_run  VARCHAR(191) NULL,
                produced_by_run  VARCHAR(191) NULL,
                seed_checksum    VARCHAR(64)  NULL,
                queued_at        TIMESTAMPTZ  NULL,
                produced_at      TIMESTAMPTZ  NULL,
                published_at     TIMESTAMPTZ  NULL,
                retired_at       TIMESTAMPTZ  NULL,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_content_base_entry UNIQUE (content_type, entry_key)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_cbe_type_status ON reach_content_base_entries (content_type, status, target_date)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_cbe_content_key ON reach_content_base_entries (content_key)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_routine_activity (
                id            BIGSERIAL PRIMARY KEY,
                content_type  VARCHAR(32)  NOT NULL,
                endpoint      VARCHAR(80)  NOT NULL,
                method        VARCHAR(8)   NOT NULL,
                hits          BIGINT       NOT NULL DEFAULT 0,
                last_status   INTEGER      NULL,
                first_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                last_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_routine_activity UNIQUE (content_type, endpoint, method)
            )
        ");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_routine_activity');
        $this->db->query('DROP TABLE IF EXISTS reach_content_base_entries');
    }
}
