<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachRoadmapDecisions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'topic_candidate_id'    => ['type' => 'BIGINT', 'null' => true],
            'content_item_id'       => ['type' => 'BIGINT', 'null' => true],
            'decision'              => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => false],
            'decision_reason'       => ['type' => 'TEXT', 'null' => true],
            'score_at_decision'     => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'rank_at_decision'      => ['type' => 'INT', 'null' => true],
            'portfolio_stream'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'requires_human_review' => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'optimizer_run_id'      => ['type' => 'BIGINT', 'null' => true],
            'work_block_id'         => ['type' => 'BIGINT', 'null' => true],
            'decided_for_date'      => ['type' => 'DATE', 'null' => false],
            'actor_type'            => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false, 'default' => 'system'],
            'decided_by'            => ['type' => 'BIGINT', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'            => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('decision');
        $this->forge->addKey('decided_for_date');
        $this->forge->addKey('optimizer_run_id');
        $this->forge->createTable('reach_roadmap_decisions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_roadmap_decisions', true);
    }
}
