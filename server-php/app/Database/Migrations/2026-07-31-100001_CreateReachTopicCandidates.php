<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachTopicCandidates extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'candidate_uuid'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'topic_cluster_id'    => ['type' => 'BIGINT', 'null' => true],
            'title'               => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'normalized_title'    => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'slug_hint'           => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'portfolio_stream'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'primary_product_id'  => ['type' => 'BIGINT', 'null' => true],
            'audience'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'industry'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'funnel_stage'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'search_intent'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'campaign'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'source'              => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'seed_keyword'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'candidate'],
            'is_locked'           => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'is_human_pinned'     => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'cooldown_until'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'last_scored_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'content_item_id'     => ['type' => 'BIGINT', 'null' => true],
            'evidence_ready'      => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'notes'               => ['type' => 'TEXT', 'null' => true],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('candidate_uuid');
        $this->forge->addKey('status');
        $this->forge->addKey('topic_cluster_id');
        $this->forge->addKey('portfolio_stream');
        $this->forge->addKey('normalized_title');
        $this->forge->createTable('reach_topic_candidates', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_topic_candidates', true);
    }
}
