<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per vetting call (ContentVettingService): which model judged which
 * draft, its decision and reasons, and the final text it approved. Vetting
 * replaces human review, so this table is the review record — what was
 * published, rejected or corrected, and why.
 */
class CreateReachAiVettingRuns extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS reach_ai_vetting_runs (
            id            BIGSERIAL PRIMARY KEY,
            content_type  VARCHAR(40)  NOT NULL,
            content_ref   VARCHAR(191) NULL,
            model         VARCHAR(120) NOT NULL,
            decision      VARCHAR(16)  NOT NULL CHECK (decision IN ('approve','revise','reject','error')),
            reasons_json  JSONB        NOT NULL DEFAULT '[]'::jsonb,
            scores_json   JSONB        NOT NULL DEFAULT '{}'::jsonb,
            input_sha256  CHAR(64)     NOT NULL,
            final_json    JSONB        NULL,
            latency_ms    INTEGER      NULL,
            error         TEXT         NULL,
            created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_ai_vetting_runs_type_created ON reach_ai_vetting_runs (content_type, created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_ai_vetting_runs_ref ON reach_ai_vetting_runs (content_ref)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_ai_vetting_runs');
    }
}
