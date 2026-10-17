<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stream H — AI visibility (GEO) execution + AI Insights.
 *
 *  - reach_ai_visibility_batches: one row per "ask every prompt" pass (the
 *    daily job or a manual run-now). Records which assistants ran and which
 *    were skipped and why, so a missing provider is visible, not silent.
 *  - reach_ai_visibility_runs.batch_id links each prompt × assistant run to
 *    its batch.
 *  - reach_ai_insight_reports: each AI Insights run — the data digest that
 *    was sent, the model's report, numbers in the report that could not be
 *    traced back to the digest, and the error when it failed.
 *  - reach_ai_insight_actions: the report's recommendations as a to-do list
 *    (open / done / dismissed).
 *  - reach_strategy_memos: weekly growth-strategy memos POSTed by the
 *    reach-growth-strategy Claude routine.
 */
class CreateReachGrowthIntelligence extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_visibility_batches (
                id               BIGSERIAL PRIMARY KEY,
                tenant_id        BIGINT NOT NULL DEFAULT 1,
                batch_date       DATE NOT NULL,
                trigger_type     VARCHAR(20) NOT NULL DEFAULT 'scheduled'
                                 CHECK (trigger_type IN ('scheduled','manual')),
                status           VARCHAR(20) NOT NULL DEFAULT 'running'
                                 CHECK (status IN ('running','completed','skipped','failed')),
                prompts_planned  INT NOT NULL DEFAULT 0,
                runs_queued      INT NOT NULL DEFAULT 0,
                providers_json   JSONB NOT NULL DEFAULT '[]'::jsonb,
                note             TEXT,
                requested_by     BIGINT,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                finished_at      TIMESTAMPTZ
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_visibility_batches_date ON reach_ai_visibility_batches (tenant_id, batch_date DESC)');

        $this->db->query('ALTER TABLE reach_ai_visibility_runs ADD COLUMN IF NOT EXISTS batch_id BIGINT REFERENCES reach_ai_visibility_batches(id) ON DELETE SET NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_visibility_runs_batch ON reach_ai_visibility_runs (batch_id)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_insight_reports (
                id                   BIGSERIAL PRIMARY KEY,
                trigger_type         VARCHAR(20) NOT NULL DEFAULT 'manual'
                                     CHECK (trigger_type IN ('scheduled','manual','bot')),
                status               VARCHAR(20) NOT NULL
                                     CHECK (status IN ('completed','failed')),
                model                VARCHAR(120),
                period_start         DATE,
                period_end           DATE,
                digest_json          JSONB NOT NULL DEFAULT '{}'::jsonb,
                digest_sha256        CHAR(64),
                report_json          JSONB,
                unverified_numbers   JSONB NOT NULL DEFAULT '[]'::jsonb,
                error                TEXT,
                latency_ms           INT,
                requested_by         BIGINT,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_insight_reports_created ON reach_ai_insight_reports (status, created_at DESC)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_insight_actions (
                id               BIGSERIAL PRIMARY KEY,
                report_id        BIGINT NOT NULL REFERENCES reach_ai_insight_reports(id) ON DELETE CASCADE,
                position         INT NOT NULL DEFAULT 0,
                title            VARCHAR(300) NOT NULL,
                detail           TEXT,
                expected_impact  VARCHAR(10) CHECK (expected_impact IN ('high','medium','low')),
                impact_rationale TEXT,
                effort           VARCHAR(10) CHECK (effort IN ('S','M','L')),
                owner_type       VARCHAR(40),
                horizon_days     INT,
                status           VARCHAR(20) NOT NULL DEFAULT 'open'
                                 CHECK (status IN ('open','done','dismissed')),
                status_note      TEXT,
                status_changed_by BIGINT,
                status_changed_at TIMESTAMPTZ,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_insight_actions_report ON reach_ai_insight_actions (report_id, position)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_insight_actions_status ON reach_ai_insight_actions (status)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_strategy_memos (
                id                 BIGSERIAL PRIMARY KEY,
                title              VARCHAR(300) NOT NULL,
                body_markdown      TEXT NOT NULL,
                period_start       DATE,
                period_end         DATE,
                routine_run_id     VARCHAR(191),
                model              VARCHAR(120),
                insights_report_id BIGINT REFERENCES reach_ai_insight_reports(id) ON DELETE SET NULL,
                sources_json       JSONB NOT NULL DEFAULT '[]'::jsonb,
                body_sha256        CHAR(64) NOT NULL,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_strategy_memos_routine_run ON reach_strategy_memos (routine_run_id) WHERE routine_run_id IS NOT NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_strategy_memos_created ON reach_strategy_memos (created_at DESC)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_strategy_memos CASCADE');
        $this->db->query('DROP TABLE IF EXISTS reach_ai_insight_actions CASCADE');
        $this->db->query('DROP TABLE IF EXISTS reach_ai_insight_reports CASCADE');
        $this->db->query('DROP INDEX IF EXISTS idx_visibility_runs_batch');
        $this->db->query('ALTER TABLE reach_ai_visibility_runs DROP COLUMN IF EXISTS batch_id');
        $this->db->query('DROP TABLE IF EXISTS reach_ai_visibility_batches CASCADE');
    }
}
