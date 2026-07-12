<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Content validation results.
 *
 * Stores the result of each validation check (fact, SEO, product_claim, etc.).
 * Waivers allow approved exceptions with mandatory reason and actor.
 */
class CreateReachContentValidations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'version_id'        => ['type' => 'BIGINT', 'null' => true],
            'validation_type'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => false],
            'validation_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'pending'],
            'score'             => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'message'           => ['type' => 'TEXT', 'null' => true],
            'details'           => ['type' => 'JSONB', 'null' => true],
            // Waiver
            'waiver_reason'     => ['type' => 'TEXT', 'null' => true],
            'waived_by'         => ['type' => 'BIGINT', 'null' => true],
            'waived_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            // Actor
            'run_by'            => ['type' => 'BIGINT', 'null' => true],
            'run_at'            => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['content_item_id', 'validation_type']);
        $this->forge->addKey('version_id');
        $this->forge->addKey('validation_status');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('version_id', 'reach_content_versions', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_content_validations', true);

        $this->db->query("ALTER TABLE reach_content_validations DROP CONSTRAINT IF EXISTS rcval_type_chk");
        $this->db->query(
            "ALTER TABLE reach_content_validations ADD CONSTRAINT rcval_type_chk "
            . "CHECK (validation_type IN ("
            . "'product_claim','fact_check','seo','readability','tone','brand_voice',"
            . "'competitor_mention','pricing_claim','compliance','legal','technical_accuracy',"
            . "'accessibility','plagiarism','word_count'"
            . "))"
        );
        $this->db->query("ALTER TABLE reach_content_validations DROP CONSTRAINT IF EXISTS rcval_status_chk");
        $this->db->query(
            "ALTER TABLE reach_content_validations ADD CONSTRAINT rcval_status_chk "
            . "CHECK (validation_status IN ('pending','passed','warning','failed','waived','skipped'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_validations', true);
    }
}
