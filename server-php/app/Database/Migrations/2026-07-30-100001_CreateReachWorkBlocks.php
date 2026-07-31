<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachWorkBlocks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                     => ['type' => 'BIGSERIAL'],
            'work_block_uuid'        => ['type' => 'UUID', 'null' => false, 'default' => new RawSql('gen_random_uuid()')],
            'block_type'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'scope'                  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'content_item_id'        => ['type' => 'BIGINT', 'null' => true],
            'content_version_id'     => ['type' => 'BIGINT', 'null' => true],
            'input_json'             => ['type' => 'JSONB', 'null' => true],
            'output_json'            => ['type' => 'JSONB', 'null' => true],
            'eligibility_status'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'pending'],
            'idempotency_key'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'priority'               => ['type' => 'INT', 'null' => false, 'default' => 0],
            'scheduled_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'attempt_count'          => ['type' => 'INT', 'null' => false, 'default' => 0],
            'max_attempts'           => ['type' => 'INT', 'null' => false, 'default' => 5],
            'dependency_ids'         => ['type' => 'JSONB', 'null' => true],
            'provider_route'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'cost_ceiling_cents'     => ['type' => 'INT', 'null' => true],
            'failure_classification' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'job_id'                 => ['type' => 'BIGINT', 'null' => true],
            'audit_ref'              => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'created_at'             => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'started_at'             => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'completed_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'updated_at'             => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('work_block_uuid');
        $this->forge->addKey(['eligibility_status', 'scheduled_at']);
        $this->forge->addKey('block_type');
        $this->forge->addKey('content_item_id');
        $this->forge->createTable('reach_work_blocks', true);

        $this->db->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS reach_work_blocks_idempotency_uidx '
            . 'ON reach_work_blocks (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS reach_work_blocks_idempotency_uidx');
        $this->forge->dropTable('reach_work_blocks', true);
    }
}
