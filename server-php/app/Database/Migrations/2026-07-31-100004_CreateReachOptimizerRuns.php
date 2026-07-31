<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachOptimizerRuns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGSERIAL'],
            'run_uuid'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'run_for_date'         => ['type' => 'DATE', 'null' => false],
            'status'               => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false, 'default' => 'running'],
            'candidates_considered'=> ['type' => 'INT', 'null' => false, 'default' => 0],
            'candidates_scored'    => ['type' => 'INT', 'null' => false, 'default' => 0],
            'decisions_created'    => ['type' => 'INT', 'null' => false, 'default' => 0],
            'work_blocks_created'  => ['type' => 'INT', 'null' => false, 'default' => 0],
            'skipped_reason'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'summary_json'         => ['type' => 'JSONB', 'null' => true],
            'weights_json'         => ['type' => 'JSONB', 'null' => true],
            'started_at'           => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'completed_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'duration_ms'          => ['type' => 'INT', 'null' => true],
            'created_at'           => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'           => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('run_uuid');
        $this->forge->addKey('run_for_date');
        $this->forge->createTable('reach_optimizer_runs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_optimizer_runs', true);
    }
}
