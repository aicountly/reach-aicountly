<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachTopicScores extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGSERIAL'],
            'topic_candidate_id'   => ['type' => 'BIGINT', 'null' => false],
            'total_score'          => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => false, 'default' => 0],
            'search_opportunity'   => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'product_priority'     => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'audience_problem'     => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'conversion_potential' => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'content_gap'          => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'seasonality'          => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'internal_link_value'  => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'evidence_readiness'   => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'deductions_json'      => ['type' => 'JSONB', 'null' => true],
            'deduction_total'      => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => false, 'default' => 0],
            'weights_json'         => ['type' => 'JSONB', 'null' => true],
            'inputs_json'          => ['type' => 'JSONB', 'null' => true],
            'trend_7d'             => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'trend_28d'            => ['type' => 'NUMERIC', 'constraint' => '6,2', 'null' => true],
            'scored_for_date'      => ['type' => 'DATE', 'null' => false],
            'is_current'           => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
            'created_at'           => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['topic_candidate_id', 'scored_for_date']);
        $this->forge->addKey('is_current');
        $this->forge->addForeignKey('topic_candidate_id', 'reach_topic_candidates', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('reach_topic_scores', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_topic_scores', true);
    }
}
