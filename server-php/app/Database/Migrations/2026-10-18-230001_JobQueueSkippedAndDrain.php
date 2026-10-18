<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Job queue honesty (stream 3, 18 Oct 2026).
 *
 * 1. reach_jobs.status gains `skipped`: a job whose integration is not
 *    configured (no Gemini key, no Search Console connection, no alert
 *    address…) is skipped with a reason instead of counted as a failure and
 *    retried five times. The Job Monitor and dashboard show skips apart.
 * 2. Pending `marketing_bot` jobs move to `default`. No documented worker ever
 *    drained `marketing_bot`, so every Bot queue dispatch sat pending for ever
 *    (and never wrote the bot report the dashboard counted). New dispatches
 *    are enqueued on `default` (MarketingBotService).
 * 3. Indexes for the 24-hour dashboard figures, the failing-types breakdown
 *    and pruning, which all filter on (status, completed_at / updated_at).
 */
class JobQueueSkippedAndDrain extends Migration
{
    private const STATUSES_OLD = "'pending','processing','completed','failed','dead_letter','cancelled'";

    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_jobs DROP CONSTRAINT IF EXISTS reach_jobs_status_chk');
        $this->db->query('ALTER TABLE reach_jobs ADD CONSTRAINT reach_jobs_status_chk CHECK (status IN (' . self::STATUSES_OLD . ",'skipped'))");

        $this->db->query("UPDATE reach_jobs SET queue = 'default', updated_at = NOW() WHERE queue = 'marketing_bot' AND status = 'pending'");

        $this->db->query('CREATE INDEX IF NOT EXISTS reach_jobs_status_completed_idx ON reach_jobs (status, completed_at)');
        $this->db->query('CREATE INDEX IF NOT EXISTS reach_jobs_status_updated_idx ON reach_jobs (status, updated_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS reach_jobs_status_updated_idx');
        $this->db->query('DROP INDEX IF EXISTS reach_jobs_status_completed_idx');
        $this->db->query("UPDATE reach_jobs SET status = 'completed' WHERE status = 'skipped'");
        $this->db->query('ALTER TABLE reach_jobs DROP CONSTRAINT IF EXISTS reach_jobs_status_chk');
        $this->db->query('ALTER TABLE reach_jobs ADD CONSTRAINT reach_jobs_status_chk CHECK (status IN (' . self::STATUSES_OLD . '))');
    }
}
