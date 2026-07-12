<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Extend reach_audit_logs with the Phase 0 correlation columns.
 *
 * Added columns (all nullable so old writers keep functioning):
 *   - actor_type      : which category of actor produced the event
 *                       (human|system|bot|service)
 *   - actor_service   : optional service slug when actor_type is not human
 *                       (e.g. "reach:worker", "reach:cron", "reach:api")
 *   - reason          : free-form audit reason (approval override, cancel
 *                       explanation, permission denial context)
 *   - request_id      : X-Request-Id / correlation id from the HTTP request
 *                       that produced this audit event, or the source job's
 *                       request id when written from the worker.
 *   - job_id          : reach_jobs.id when the event is produced by (or
 *                       associated with) an async job.
 *
 * Indexes on request_id and job_id make cross-cutting correlation lookups
 * (e.g. "give me every audit row for request_id=X") fast enough for
 * incident response.
 */
class ExtendReachAuditLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('reach_audit_logs', [
            'actor_type'    => ['type' => 'VARCHAR', 'constraint' => 16,  'null' => true, 'after' => 'user_id'],
            'actor_service' => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true, 'after' => 'actor_type'],
            'reason'        => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true, 'after' => 'metadata'],
            'request_id'    => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true, 'after' => 'reason'],
            'job_id'        => ['type' => 'BIGINT',                        'null' => true, 'after' => 'request_id'],
        ]);

        $this->forge->addKey('request_id', false, false, 'idx_reach_audit_logs_request_id');
        $this->forge->addKey('job_id',      false, false, 'idx_reach_audit_logs_job_id');
        $this->forge->processIndexes('reach_audit_logs');

        // Enforce actor_type enum defensively at the DB.
        $this->db->query(new RawSql(
            "ALTER TABLE reach_audit_logs
             ADD CONSTRAINT reach_audit_logs_actor_type_chk
             CHECK (actor_type IS NULL OR actor_type IN ('human','system','bot','service'))"
        ));
    }

    public function down(): void
    {
        $this->db->query(new RawSql(
            'ALTER TABLE reach_audit_logs DROP CONSTRAINT IF EXISTS reach_audit_logs_actor_type_chk'
        ));
        $this->forge->dropKey('reach_audit_logs', 'idx_reach_audit_logs_request_id', false);
        $this->forge->dropKey('reach_audit_logs', 'idx_reach_audit_logs_job_id', false);
        $this->forge->dropColumn('reach_audit_logs', [
            'actor_type', 'actor_service', 'reason', 'request_id', 'job_id',
        ]);
    }
}
