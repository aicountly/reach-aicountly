<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachSearchIntents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'slug'                => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'intent_text'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false],
            'intent_type'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'informational'],
            'funnel_stage'        => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => false, 'default' => 'top'],
            'search_volume'       => ['type' => 'INTEGER', 'null' => true],
            'difficulty_score'    => ['type' => 'INTEGER', 'null' => true],
            'notes'               => ['type' => 'TEXT', 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
            'internal_notes'      => ['type' => 'JSONB', 'null' => true],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'updated_by'          => ['type' => 'BIGINT', 'null' => true],
            'reviewed_by'         => ['type' => 'BIGINT', 'null' => true],
            'reviewed_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'         => ['type' => 'BIGINT', 'null' => true],
            'approved_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_actor_type'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'request_id'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'deleted_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->addKey('intent_type');
        $this->forge->addKey('funnel_stage');
        $this->forge->createTable('reach_search_intents', true);

        $this->db->query(
            "ALTER TABLE reach_search_intents ADD CONSTRAINT reach_search_intents_status_chk "
            . "CHECK (status IN ('draft','needs_review','approved','rejected','deprecated','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_search_intents ADD CONSTRAINT reach_search_intents_type_chk "
            . "CHECK (intent_type IN ('informational','navigational','transactional','commercial'))"
        );
        $this->db->query(
            "ALTER TABLE reach_search_intents ADD CONSTRAINT reach_search_intents_funnel_chk "
            . "CHECK (funnel_stage IN ('top','middle','bottom'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_search_intents', true);
    }
}
