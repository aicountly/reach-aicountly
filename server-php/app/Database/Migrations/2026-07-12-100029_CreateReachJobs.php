<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * PostgreSQL-backed async job queue.
 *
 * Status enum:
 *   pending      — waiting to be reserved
 *   processing   — leased by a worker
 *   completed    — finished successfully
 *   failed       — this attempt failed but may be retried
 *   dead_letter  — max_attempts reached
 *   cancelled    — cancelled by an operator
 *
 * Reservation is via `SELECT ... FOR UPDATE SKIP LOCKED LIMIT 1` inside a
 * transaction; recovery of leases past their lease_expires_at is handled by
 * `php spark reach:schedule`.
 */
class CreateReachJobs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGSERIAL'],
            'job_uuid'           => ['type' => 'UUID', 'null' => false, 'default' => new RawSql('gen_random_uuid()')],
            'job_type'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'queue'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'default'],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'pending'],
            'priority'           => ['type' => 'INTEGER', 'null' => false, 'default' => 0],

            'payload_json'       => ['type' => 'JSONB',   'null' => true],
            'result_json'        => ['type' => 'JSONB',   'null' => true],
            'error_message'      => ['type' => 'TEXT',    'null' => true],

            'attempts'           => ['type' => 'INTEGER', 'null' => false, 'default' => 0],
            'max_attempts'       => ['type' => 'INTEGER', 'null' => false, 'default' => 5],

            'available_at'       => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'scheduled_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'reserved_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'started_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'completed_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'lease_expires_at'   => ['type' => 'TIMESTAMPTZ', 'null' => true],

            'worker_id'          => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'progress'           => ['type' => 'INTEGER', 'null' => true],
            'progress_message'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

            'idempotency_key'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'request_id'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'correlation_id'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'enqueued_by_user_id' => ['type' => 'BIGINT', 'null' => true],
            'enqueued_actor_type' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],

            'created_at'         => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'         => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('job_uuid');
        $this->forge->addKey(['queue', 'status', 'available_at']);
        $this->forge->addKey(['status', 'lease_expires_at']);
        $this->forge->addKey('job_type');
        $this->forge->addKey('request_id');

        $this->forge->createTable('reach_jobs', true);

        $this->db->query(
            "ALTER TABLE reach_jobs ADD CONSTRAINT reach_jobs_status_chk "
            . "CHECK (status IN ('pending','processing','completed','failed','dead_letter','cancelled'))"
        );
        $this->db->query(
            "ALTER TABLE reach_jobs ADD CONSTRAINT reach_jobs_actor_type_chk "
            . "CHECK (enqueued_actor_type IS NULL OR enqueued_actor_type IN ('human','system','bot','service'))"
        );
        $this->db->query(
            "CREATE UNIQUE INDEX reach_jobs_idempotency_uidx "
            . "ON reach_jobs (idempotency_key) WHERE idempotency_key IS NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS reach_jobs_idempotency_uidx');
        $this->forge->dropTable('reach_jobs', true);
    }
}
