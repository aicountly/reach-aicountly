<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Live-content clean-up (stream 4, 18 Oct 2026).
 *
 * 1. reach_content_quality_checks — one row per re-vet of a content version
 *    (reach:content-revet, the daily quality sweep, the publish gate): the
 *    heuristic flags, the vetting model's verdict and what Reach did about it.
 * 2. reach_content_regenerations — the regeneration queue. An item whose
 *    current text failed vetting is taken off the site and queued here with
 *    its title / slug / keyword; the blog and KB routines read
 *    GET v1/automation/regenerate/next first and submit the replacement
 *    through the normal path, republished at the same slug.
 * 3. reach_routine_submissions.origin — 'routine' (the routine wrote it, and
 *    it counts against the daily quota), 'regeneration' (a routine rewrite of
 *    a failed item; counts) or 'revet' (an older approved item adopted into
 *    the pipeline after it passed re-vetting; publishes nothing new, so it
 *    does not count).
 */
class CreateReachContentQualityCleanup extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS reach_content_quality_checks (
            id                  BIGSERIAL PRIMARY KEY,
            subject_type        VARCHAR(32)  NOT NULL,
            subject_id          BIGINT       NOT NULL,
            content_version_id  BIGINT       NULL,
            version_checksum    VARCHAR(64)  NULL,
            scope               VARCHAR(16)  NOT NULL CHECK (scope IN ('unpublished','live')),
            trigger_source      VARCHAR(32)  NOT NULL DEFAULT 'command',
            heuristic_flags     JSONB        NOT NULL DEFAULT '[]'::jsonb,
            decision            VARCHAR(16)  NOT NULL CHECK (decision IN ('pass','revise','fail','unavailable','skipped')),
            vetting_decision    VARCHAR(16)  NULL,
            vetting_run_id      BIGINT       NULL,
            reasons             JSONB        NOT NULL DEFAULT '[]'::jsonb,
            action              VARCHAR(48)  NOT NULL DEFAULT 'none',
            dry_run             BOOLEAN      NOT NULL DEFAULT FALSE,
            created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcqc_subject ON reach_content_quality_checks (subject_type, subject_id, created_at DESC)');

        $this->db->query("CREATE TABLE IF NOT EXISTS reach_content_regenerations (
            id                  BIGSERIAL PRIMARY KEY,
            content_item_id     BIGINT       NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
            content_type        VARCHAR(32)  NOT NULL CHECK (content_type IN ('blog','knowledge_base')),
            title               VARCHAR(300) NOT NULL,
            slug                VARCHAR(300) NOT NULL,
            primary_keyword     VARCHAR(255) NULL,
            product_slug        VARCHAR(100) NULL,
            reasons             JSONB        NOT NULL DEFAULT '[]'::jsonb,
            status              VARCHAR(16)  NOT NULL DEFAULT 'queued'
                                CHECK (status IN ('queued','claimed','submitted','live','cancelled')),
            was_live            BOOLEAN      NOT NULL DEFAULT FALSE,
            claimed_until       TIMESTAMPTZ  NULL,
            claim_count         INTEGER      NOT NULL DEFAULT 0,
            replaced_version_id BIGINT       NULL,
            new_version_id      BIGINT       NULL,
            submission_id       BIGINT       NULL,
            quality_check_id    BIGINT       NULL,
            created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            completed_at        TIMESTAMPTZ  NULL
        )");
        $this->db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_rcr_open_item ON reach_content_regenerations (content_item_id)
                          WHERE status IN ('queued','claimed','submitted')");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcr_status ON reach_content_regenerations (content_type, status, id)');

        $this->db->query("ALTER TABLE reach_routine_submissions ADD COLUMN IF NOT EXISTS origin VARCHAR(32) NOT NULL DEFAULT 'routine'");
        $this->db->query('ALTER TABLE reach_routine_submissions DROP CONSTRAINT IF EXISTS reach_routine_submissions_origin_chk');
        $this->db->query("ALTER TABLE reach_routine_submissions ADD CONSTRAINT reach_routine_submissions_origin_chk
                          CHECK (origin IN ('routine','regeneration','revet'))");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_routine_submissions DROP CONSTRAINT IF EXISTS reach_routine_submissions_origin_chk');
        $this->db->query('ALTER TABLE reach_routine_submissions DROP COLUMN IF EXISTS origin');
        $this->db->query('DROP TABLE IF EXISTS reach_content_regenerations');
        $this->db->query('DROP TABLE IF EXISTS reach_content_quality_checks');
    }
}
