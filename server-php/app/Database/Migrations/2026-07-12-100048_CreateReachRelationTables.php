<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * All Phase 1 many-to-many junction tables.
 *
 * Each table has: both FK columns, unique constraint on the FK pair,
 * created_by, and created_at. No updated_at needed (relations are
 * inserted or deleted, not mutated).
 */
class CreateReachRelationTables extends Migration
{
    public function up(): void
    {
        // ── products ↔ personas ──────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'product_id' => ['type' => 'BIGINT', 'null' => false],
            'persona_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['product_id', 'persona_id']);
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('persona_id', 'reach_personas', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_product_personas', true);

        // ── products ↔ industries ────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'product_id'  => ['type' => 'BIGINT', 'null' => false],
            'industry_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['product_id', 'industry_id']);
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('industry_id', 'reach_industries', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_product_industries', true);

        // ── products ↔ markets ───────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'product_id' => ['type' => 'BIGINT', 'null' => false],
            'market_id'  => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['product_id', 'market_id']);
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('market_id', 'reach_markets', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_product_markets', true);

        // ── modules ↔ personas ───────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'module_id'  => ['type' => 'BIGINT', 'null' => false],
            'persona_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['module_id', 'persona_id']);
        $this->forge->addForeignKey('module_id', 'reach_product_modules', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('persona_id', 'reach_personas', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_module_personas', true);

        // ── features ↔ personas ──────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'feature_id' => ['type' => 'BIGINT', 'null' => false],
            'persona_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['feature_id', 'persona_id']);
        $this->forge->addForeignKey('feature_id', 'reach_product_features', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('persona_id', 'reach_personas', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_feature_personas', true);

        // ── features ↔ industries ────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'feature_id'  => ['type' => 'BIGINT', 'null' => false],
            'industry_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['feature_id', 'industry_id']);
        $this->forge->addForeignKey('feature_id', 'reach_product_features', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('industry_id', 'reach_industries', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_feature_industries', true);

        // ── features ↔ business problems ────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'feature_id'  => ['type' => 'BIGINT', 'null' => false],
            'problem_id'  => ['type' => 'BIGINT', 'null' => false],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['feature_id', 'problem_id']);
        $this->forge->addForeignKey('feature_id', 'reach_product_features', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('problem_id', 'reach_business_problems', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_feature_problems', true);

        // ── search intents ↔ products ────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'intent_id'  => ['type' => 'BIGINT', 'null' => false],
            'product_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['intent_id', 'product_id']);
        $this->forge->addForeignKey('intent_id', 'reach_search_intents', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_intent_products', true);

        // ── search intents ↔ modules ─────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'intent_id'  => ['type' => 'BIGINT', 'null' => false],
            'module_id'  => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['intent_id', 'module_id']);
        $this->forge->addForeignKey('intent_id', 'reach_search_intents', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('module_id', 'reach_product_modules', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_intent_modules', true);

        // ── search intents ↔ features ────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'intent_id'  => ['type' => 'BIGINT', 'null' => false],
            'feature_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['intent_id', 'feature_id']);
        $this->forge->addForeignKey('intent_id', 'reach_search_intents', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('feature_id', 'reach_product_features', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_intent_features', true);

        // ── search intents ↔ personas ────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'intent_id'  => ['type' => 'BIGINT', 'null' => false],
            'persona_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['intent_id', 'persona_id']);
        $this->forge->addForeignKey('intent_id', 'reach_search_intents', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('persona_id', 'reach_personas', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_intent_personas', true);

        // ── search intents ↔ topic clusters ─────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'intent_id'  => ['type' => 'BIGINT', 'null' => false],
            'cluster_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['intent_id', 'cluster_id']);
        $this->forge->addForeignKey('intent_id', 'reach_search_intents', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('cluster_id', 'reach_topic_clusters', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_intent_topic_clusters', true);

        // ── products ↔ evidence ──────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'product_id'  => ['type' => 'BIGINT', 'null' => false],
            'evidence_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['product_id', 'evidence_id']);
        $this->forge->addForeignKey('product_id', 'reach_products', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('evidence_id', 'reach_evidence', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_product_evidence', true);

        // ── features ↔ evidence ──────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'feature_id'  => ['type' => 'BIGINT', 'null' => false],
            'evidence_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['feature_id', 'evidence_id']);
        $this->forge->addForeignKey('feature_id', 'reach_product_features', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('evidence_id', 'reach_evidence', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_feature_evidence', true);

        // ── claims ↔ evidence ────────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'claim_id'    => ['type' => 'BIGINT', 'null' => false],
            'evidence_id' => ['type' => 'BIGINT', 'null' => false],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['claim_id', 'evidence_id']);
        $this->forge->addForeignKey('claim_id', 'reach_product_claims', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('evidence_id', 'reach_evidence', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_claim_evidence', true);
    }

    public function down(): void
    {
        // Drop in reverse dependency order
        $tables = [
            'reach_claim_evidence',
            'reach_feature_evidence',
            'reach_product_evidence',
            'reach_intent_topic_clusters',
            'reach_intent_personas',
            'reach_intent_features',
            'reach_intent_modules',
            'reach_intent_products',
            'reach_feature_problems',
            'reach_feature_industries',
            'reach_feature_personas',
            'reach_module_personas',
            'reach_product_markets',
            'reach_product_industries',
            'reach_product_personas',
        ];
        foreach ($tables as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
