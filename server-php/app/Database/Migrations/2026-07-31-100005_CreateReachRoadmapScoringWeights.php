<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachRoadmapScoringWeights extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGSERIAL'],
            'search_opportunity'   => ['type' => 'INT', 'null' => false, 'default' => 20],
            'product_priority'     => ['type' => 'INT', 'null' => false, 'default' => 20],
            'audience_problem'     => ['type' => 'INT', 'null' => false, 'default' => 15],
            'conversion_potential' => ['type' => 'INT', 'null' => false, 'default' => 15],
            'content_gap'          => ['type' => 'INT', 'null' => false, 'default' => 10],
            'seasonality'          => ['type' => 'INT', 'null' => false, 'default' => 10],
            'internal_link_value'  => ['type' => 'INT', 'null' => false, 'default' => 5],
            'evidence_readiness'   => ['type' => 'INT', 'null' => false, 'default' => 5],
            'deductions_json'      => ['type' => 'JSONB', 'null' => true],
            'stability_json'       => ['type' => 'JSONB', 'null' => true],
            'updated_by'           => ['type' => 'BIGINT', 'null' => true],
            'created_at'           => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'           => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('reach_roadmap_scoring_weights', true);

        $this->db->table('reach_roadmap_scoring_weights')->insert([
            'search_opportunity'   => 20,
            'product_priority'     => 20,
            'audience_problem'     => 15,
            'conversion_potential' => 15,
            'content_gap'          => 10,
            'seasonality'          => 10,
            'internal_link_value'  => 5,
            'evidence_readiness'   => 5,
            'deductions_json'      => json_encode([
                'cannibalisation'              => 15,
                'near_duplicate'               => 25,
                'weak_evidence'                => 10,
                'product_feature_unavailable'  => 20,
                'recently_generated'           => 10,
                'portfolio_over_concentration' => 15,
            ], JSON_UNESCAPED_SLASHES),
            'stability_json' => json_encode([
                'min_score_movement'        => 5,
                'cooldown_days'             => 14,
                'max_daily_candidates'      => 10,
                'max_weekly_publications'   => 5,
                'portfolio_over_pct_margin' => 15,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_roadmap_scoring_weights', true);
    }
}
