<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bot remediation: blocked articles and "What to do next" cards get fixed by
 * the bot — on its own in AUTO mode, or when the owner presses the button.
 *
 * 1. reach_routine_submissions
 *    - status `awaiting_rewrite`: the bot asked the blog / KB routine for a
 *      same-slug rewrite (reach_content_regenerations) and the old text is no
 *      longer retried;
 *    - block_kind: why publishing stopped (vet_refused, not_approved,
 *      version_mismatch, approval_revoked, site_refused, publish_refused,
 *      auto_publish_off, vetting_unavailable), so the remedy is chosen from a
 *      recorded reason rather than from error text;
 *    - remediation_count / remediation_step / remediated_at: the bot's ladder
 *      (retry → re-vet → rewrite → a person), one step at a time.
 * 2. reach_content_base_entries.origin `signal`: a topic the bot queued from a
 *    Search Console opportunity ("Push <query> onto page one").
 * 3. reach_recommendation_fixes: every fix the bot made or was asked to make
 *    for a Command centre card — the card shows it, and the bot does not do
 *    the same thing twice inside the cooldown.
 */
class AddBotRemediation extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_routine_submissions DROP CONSTRAINT IF EXISTS reach_routine_submissions_status_check');
        $this->db->query("ALTER TABLE reach_routine_submissions ADD CONSTRAINT reach_routine_submissions_status_check
                          CHECK (status IN ('pending_vetting','rejected','held_high_risk','awaiting_cover','approved',
                                            'publish_blocked','publishing','live','publish_failed','awaiting_rewrite'))");
        $this->db->query('ALTER TABLE reach_routine_submissions ADD COLUMN IF NOT EXISTS block_kind VARCHAR(32) NULL');
        $this->db->query('ALTER TABLE reach_routine_submissions ADD COLUMN IF NOT EXISTS remediation_count INTEGER NOT NULL DEFAULT 0');
        $this->db->query('ALTER TABLE reach_routine_submissions ADD COLUMN IF NOT EXISTS remediation_step VARCHAR(32) NULL');
        $this->db->query('ALTER TABLE reach_routine_submissions ADD COLUMN IF NOT EXISTS remediated_at TIMESTAMPTZ NULL');

        $this->db->query('ALTER TABLE reach_content_base_entries DROP CONSTRAINT IF EXISTS reach_content_base_entries_origin_check');
        $this->db->query("ALTER TABLE reach_content_base_entries ADD CONSTRAINT reach_content_base_entries_origin_check
                          CHECK (origin IN ('seed','routine','signal'))");

        $this->db->query("CREATE TABLE IF NOT EXISTS reach_recommendation_fixes (
            id            BIGSERIAL PRIMARY KEY,
            card_id       VARCHAR(80)  NOT NULL,
            kind          VARCHAR(32)  NOT NULL,
            actor         VARCHAR(16)  NOT NULL CHECK (actor IN ('bot','operator')),
            requested_by  BIGINT       NULL,
            status        VARCHAR(16)  NOT NULL CHECK (status IN ('queued','refused','failed')),
            message       TEXT         NOT NULL,
            ref_type      VARCHAR(32)  NULL,
            ref_id        VARCHAR(64)  NULL,
            params_json   JSONB        NOT NULL DEFAULT '{}'::jsonb,
            created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rrf_card ON reach_recommendation_fixes (card_id, created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rrf_kind_actor ON reach_recommendation_fixes (kind, actor, created_at DESC)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_recommendation_fixes');
        $this->db->query("UPDATE reach_content_base_entries SET origin = 'routine' WHERE origin = 'signal'");
        $this->db->query('ALTER TABLE reach_content_base_entries DROP CONSTRAINT IF EXISTS reach_content_base_entries_origin_check');
        $this->db->query("ALTER TABLE reach_content_base_entries ADD CONSTRAINT reach_content_base_entries_origin_check CHECK (origin IN ('seed','routine'))");
        $this->db->query("UPDATE reach_routine_submissions SET status = 'publish_failed' WHERE status = 'awaiting_rewrite'");
        $this->db->query('ALTER TABLE reach_routine_submissions DROP CONSTRAINT IF EXISTS reach_routine_submissions_status_check');
        $this->db->query("ALTER TABLE reach_routine_submissions ADD CONSTRAINT reach_routine_submissions_status_check
                          CHECK (status IN ('pending_vetting','rejected','held_high_risk','awaiting_cover','approved',
                                            'publish_blocked','publishing','live','publish_failed'))");
        foreach (['block_kind', 'remediation_count', 'remediation_step', 'remediated_at'] as $c) {
            $this->db->query("ALTER TABLE reach_routine_submissions DROP COLUMN IF EXISTS {$c}");
        }
    }
}
