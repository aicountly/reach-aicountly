<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The daily analytics report (08:00 Asia/Kolkata), one row per report date.
 *
 * status:
 *   generated — built and stored, not sent (preview / --no-send / job without recipients)
 *   sent      — the mail gateway accepted it for every recipient in sent_to
 *   partial   — accepted for some recipients; `error` names the rest
 *   failed    — not sent (e.g. EMAIL_API_KEY missing, gateway rejected); `error` says why
 *
 * The job is idempotent on report_date: a row already `sent` is never re-sent
 * by the scheduler, only by an explicit "Send now".
 */
class CreateReachDailyReports extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_daily_reports (
                id            BIGSERIAL PRIMARY KEY,
                report_date   DATE NOT NULL,
                status        VARCHAR(16) NOT NULL DEFAULT 'generated'
                              CHECK (status IN ('generated','sent','partial','failed')),
                subject       VARCHAR(255),
                html          TEXT NOT NULL DEFAULT '',
                text_body     TEXT NOT NULL DEFAULT '',
                summary_json  JSONB NOT NULL DEFAULT '{}'::jsonb,
                sent_to       TEXT,
                error         TEXT,
                send_attempts INT NOT NULL DEFAULT 0,
                sent_at       TIMESTAMPTZ,
                triggered_by  VARCHAR(40),
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_daily_report_date UNIQUE (report_date)
            )
        ");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_daily_reports');
    }
}
