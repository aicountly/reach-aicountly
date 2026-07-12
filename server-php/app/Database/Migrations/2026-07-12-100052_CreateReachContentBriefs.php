<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Content brief table.
 *
 * One brief per content item. JSONB columns for multi-value fields.
 */
class CreateReachContentBriefs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'content_item_id'       => ['type' => 'BIGINT', 'null' => false],
            'objective'             => ['type' => 'TEXT', 'null' => true],
            'audience_description'  => ['type' => 'TEXT', 'null' => true],
            'persona_id'            => ['type' => 'BIGINT', 'null' => true],
            'funnel_stage'          => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'primary_keyword'       => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'secondary_keywords'    => ['type' => 'JSONB', 'null' => true],
            'questions_to_answer'   => ['type' => 'JSONB', 'null' => true],
            'required_claim_ids'    => ['type' => 'JSONB', 'null' => true],
            'excluded_claim_ids'    => ['type' => 'JSONB', 'null' => true],
            'cta'                   => ['type' => 'TEXT', 'null' => true],
            'tone'                  => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'min_word_count'        => ['type' => 'INT', 'null' => true],
            'max_word_count'        => ['type' => 'INT', 'null' => true],
            'format_notes'          => ['type' => 'TEXT', 'null' => true],
            'due_date'              => ['type' => 'DATE', 'null' => true],
            'sources'               => ['type' => 'JSONB', 'null' => true],
            'competitor_urls'       => ['type' => 'JSONB', 'null' => true],
            'is_approved'           => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'approved_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'           => ['type' => 'BIGINT', 'null' => true],
            // Actor
            'created_by'            => ['type' => 'BIGINT', 'null' => true],
            'updated_by'            => ['type' => 'BIGINT', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'            => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_briefs', true);

        $this->db->query("ALTER TABLE reach_content_briefs DROP CONSTRAINT IF EXISTS rcb_funnel_stage_chk");
        $this->db->query(
            "ALTER TABLE reach_content_briefs ADD CONSTRAINT rcb_funnel_stage_chk "
            . "CHECK (funnel_stage IS NULL OR funnel_stage IN ('top','middle','bottom'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_briefs', true);
    }
}
