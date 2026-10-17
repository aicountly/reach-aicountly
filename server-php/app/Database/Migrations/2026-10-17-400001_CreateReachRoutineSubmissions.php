<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Claude Routine submissions (blogs and knowledge-base articles) and where
 * each one is in the vet → approve → cover → publish → verify pipeline.
 *
 * One row per submitted draft. The Gemini vetting run that decided it is the
 * verification of record (vetting_run_id → reach_ai_vetting_runs), and the
 * blog approval row carries the same id so the audit trail shows the system
 * account approved it *because* the vetting model did.
 *
 * Also seeds the launch apps that had no KB cadence row (Billing, Inventory,
 * Notes, Drive) — without one the KB ingest refuses their articles.
 */
class CreateReachRoutineSubmissions extends Migration
{
    private const LAUNCH_CADENCE = [
        ['billing', 'normal', 2], ['inventory', 'normal', 2],
        ['notes', 'small', 1], ['drive', 'small', 1],
    ];

    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS reach_routine_submissions (
            id                    BIGSERIAL PRIMARY KEY,
            content_type          VARCHAR(32)  NOT NULL CHECK (content_type IN ('blog','knowledge_base')),
            content_item_id       BIGINT       NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
            content_key           VARCHAR(120) NULL,
            status                VARCHAR(32)  NOT NULL DEFAULT 'pending_vetting'
                                  CHECK (status IN ('pending_vetting','rejected','held_high_risk','awaiting_cover',
                                                    'approved','publish_blocked','publishing','live','publish_failed')),
            decision              VARCHAR(32)  NULL,
            reasons_json          JSONB        NOT NULL DEFAULT '[]'::jsonb,
            scores_json           JSONB        NOT NULL DEFAULT '{}'::jsonb,
            vetting_model         VARCHAR(120) NULL,
            vetting_run_id        BIGINT       NULL,
            vetting_run_ids_json  JSONB        NOT NULL DEFAULT '[]'::jsonb,
            vetting_attempts      INTEGER      NOT NULL DEFAULT 0,
            next_attempt_at       TIMESTAMPTZ  NULL,
            risk_flag             VARCHAR(64)  NULL,
            approved_by           BIGINT       NULL,
            approved_at           TIMESTAMPTZ  NULL,
            approval_id           BIGINT       NULL,
            final_version_id      BIGINT       NULL,
            final_checksum        VARCHAR(64)  NULL,
            cover_status          VARCHAR(32)  NULL,
            cover_url             TEXT         NULL,
            cover_alt             TEXT         NULL,
            cover_attempts        INTEGER      NOT NULL DEFAULT 0,
            next_cover_attempt_at TIMESTAMPTZ  NULL,
            deployment_id         BIGINT       NULL,
            publish_attempts      INTEGER      NOT NULL DEFAULT 0,
            published_at          TIMESTAMPTZ  NULL,
            verified_at           TIMESTAMPTZ  NULL,
            locked_until          TIMESTAMPTZ  NULL,
            last_error            TEXT         NULL,
            submission_json       JSONB        NOT NULL DEFAULT '{}'::jsonb,
            created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_routine_submissions_item ON reach_routine_submissions (content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_routine_submissions_type_created ON reach_routine_submissions (content_type, created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_routine_submissions_status ON reach_routine_submissions (status)');

        $this->db->query('ALTER TABLE reach_blog_content_approvals ADD COLUMN IF NOT EXISTS vetting_run_id BIGINT NULL');

        // tableExists() can answer from a table list cached before this run.
        $present = $this->db->query("SELECT to_regclass('reach_kb_product_cadence') IS NOT NULL AS present")->getRow()->present ?? false;
        if (in_array($present, [true, 't', 1, '1'], true)) {
            foreach (self::LAUNCH_CADENCE as [$slug, $tier, $quota]) {
                $this->db->query(
                    'INSERT INTO reach_kb_product_cadence (product_slug, tier, daily_quota) VALUES (?, ?, ?) ON CONFLICT (product_slug) DO NOTHING',
                    [$slug, $tier, $quota]
                );
            }
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_routine_submissions');
        $this->db->query('ALTER TABLE reach_blog_content_approvals DROP COLUMN IF EXISTS vetting_run_id');
        // Cadence rows are left in place: an operator may have tuned them.
    }
}
