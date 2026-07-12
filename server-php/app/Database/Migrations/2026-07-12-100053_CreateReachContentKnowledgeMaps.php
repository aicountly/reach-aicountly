<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Content ↔ Phase 1 knowledge entity junction tables.
 *
 * 14 tables mapping content_item_id to each Phase 1 entity type.
 * Each has a UNIQUE constraint on the FK pair to prevent duplicates.
 */
class CreateReachContentKnowledgeMaps extends Migration
{
    private function makeJunction(string $table, string $fkCol, string $refTable): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'content_item_id' => ['type' => 'BIGINT', 'null' => false],
            $fkCol            => ['type' => 'BIGINT', 'null' => false],
            'created_by'      => ['type' => 'BIGINT', 'null' => true],
            'created_at'      => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['content_item_id', $fkCol]);
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->addForeignKey($fkCol, $refTable, 'id', '', 'CASCADE');
        $this->forge->createTable($table, true);
    }

    public function up(): void
    {
        $this->makeJunction('reach_content_product_map',       'product_id',        'reach_products');
        $this->makeJunction('reach_content_module_map',        'module_id',         'reach_modules');
        $this->makeJunction('reach_content_feature_map',       'feature_id',        'reach_features');
        $this->makeJunction('reach_content_persona_map',       'persona_id',        'reach_personas');
        $this->makeJunction('reach_content_industry_map',      'industry_id',       'reach_industries');
        $this->makeJunction('reach_content_market_map',        'market_id',         'reach_markets');
        $this->makeJunction('reach_content_problem_map',       'problem_id',        'reach_business_problems');
        $this->makeJunction('reach_content_search_intent_map', 'search_intent_id',  'reach_search_intents');
        $this->makeJunction('reach_content_topic_map',         'topic_cluster_id',  'reach_topic_clusters');
        $this->makeJunction('reach_content_claim_map',         'claim_id',          'reach_product_claims');
        $this->makeJunction('reach_content_evidence_map',      'evidence_id',       'reach_evidence');
        $this->makeJunction('reach_content_source_map',        'source_id',         'reach_sources');
        $this->makeJunction('reach_content_citation_map',      'citation_id',       'reach_citations');
        $this->makeJunction('reach_content_brand_rule_map',    'brand_rule_id',     'reach_brand_rules');
    }

    public function down(): void
    {
        $tables = [
            'reach_content_brand_rule_map',
            'reach_content_citation_map',
            'reach_content_source_map',
            'reach_content_evidence_map',
            'reach_content_claim_map',
            'reach_content_topic_map',
            'reach_content_search_intent_map',
            'reach_content_problem_map',
            'reach_content_market_map',
            'reach_content_industry_map',
            'reach_content_persona_map',
            'reach_content_feature_map',
            'reach_content_module_map',
            'reach_content_product_map',
        ];
        foreach ($tables as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
