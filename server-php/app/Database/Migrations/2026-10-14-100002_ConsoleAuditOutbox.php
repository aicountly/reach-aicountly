<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * reach_console_sync_logs becomes the outbox for Reach's central audit to Console
 * (ConsoleAuditClient → POST {CONSOLE_API_URL}/portal/audit, console-react-app migration 067).
 *
 * Reach used to post each event once to {CONSOLE_API_BASE_URL}/audit — a route Console never
 * had — and only record the failure here. A row is now written `pending` with the Console event
 * (`event`), sent at once, and retried with back-off by `php spark reach:console-audit-deliver`
 * until Console answers stored / duplicate (→ delivered, `event` cleared) or rejected (kept, not
 * retried); after 12 failed attempts it is `dead`. external_id is what Console de-duplicates on.
 * Rows written before this migration keep status NULL (single attempts, never retried).
 */
class ConsoleAuditOutbox extends Migration
{
    public function up(): void
    {
        foreach ([
            'ADD COLUMN IF NOT EXISTS external_id VARCHAR(128) NULL',
            'ADD COLUMN IF NOT EXISTS status VARCHAR(16) NULL',
            'ADD COLUMN IF NOT EXISTS attempts INTEGER NOT NULL DEFAULT 0',
            'ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMPTZ NULL',
            'ADD COLUMN IF NOT EXISTS event JSONB NULL',
            'ADD COLUMN IF NOT EXISTS console_audit_id BIGINT NULL',
        ] as $ddl) {
            $this->db->query("ALTER TABLE reach_console_sync_logs {$ddl}");
        }
        $this->db->query('ALTER TABLE reach_console_sync_logs DROP CONSTRAINT IF EXISTS reach_console_sync_logs_status_check');
        $this->db->query("ALTER TABLE reach_console_sync_logs ADD CONSTRAINT reach_console_sync_logs_status_check
            CHECK (status IS NULL OR status IN ('pending','delivered','rejected','dead'))");
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_reach_console_sync_logs_external_id
            ON reach_console_sync_logs (external_id) WHERE external_id IS NOT NULL');
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_reach_console_sync_logs_due
            ON reach_console_sync_logs (next_attempt_at) WHERE status = 'pending'");
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_reach_console_sync_logs_due');
        $this->db->query('DROP INDEX IF EXISTS uq_reach_console_sync_logs_external_id');
        $this->db->query('ALTER TABLE reach_console_sync_logs DROP CONSTRAINT IF EXISTS reach_console_sync_logs_status_check');
        foreach (['console_audit_id', 'event', 'next_attempt_at', 'attempts', 'status', 'external_id'] as $col) {
            $this->db->query("ALTER TABLE reach_console_sync_logs DROP COLUMN IF EXISTS {$col}");
        }
    }
}
