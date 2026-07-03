<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachKeywordIdeas extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGSERIAL'],
            'keyword'        => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'search_intent'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'priority'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'medium'],
            'source'         => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'manual'],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'open'],
            'notes'          => ['type' => 'TEXT', 'null' => true],
            'created_by'     => ['type' => 'BIGINT', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'     => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('keyword');
        $this->forge->addKey('status');
        $this->forge->createTable('reach_keyword_ideas', true);

        $this->db->query(
            "ALTER TABLE reach_keyword_ideas DROP CONSTRAINT IF EXISTS reach_keyword_ideas_priority_check"
        );
        $this->db->query(
            "ALTER TABLE reach_keyword_ideas ADD CONSTRAINT reach_keyword_ideas_priority_check "
            . "CHECK (priority IN ('low','medium','high','urgent'))"
        );
        $this->db->query(
            "ALTER TABLE reach_keyword_ideas DROP CONSTRAINT IF EXISTS reach_keyword_ideas_source_check"
        );
        $this->db->query(
            "ALTER TABLE reach_keyword_ideas ADD CONSTRAINT reach_keyword_ideas_source_check "
            . "CHECK (source IN ('manual','bot','import'))"
        );
        $this->db->query(
            "ALTER TABLE reach_keyword_ideas DROP CONSTRAINT IF EXISTS reach_keyword_ideas_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_keyword_ideas ADD CONSTRAINT reach_keyword_ideas_status_check "
            . "CHECK (status IN ('open','planned','in_progress','done','archived'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_keyword_ideas', true);
    }
}
