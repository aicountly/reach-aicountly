<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachApprovals extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'subject_type'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'subject_id'        => ['type' => 'BIGINT', 'null' => false],
            'summary'           => ['type' => 'TEXT', 'null' => true],
            'requested_by'      => ['type' => 'BIGINT', 'null' => true],
            'decision'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'],
            'decided_by'        => ['type' => 'BIGINT', 'null' => true],
            'decided_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'note'              => ['type' => 'TEXT', 'null' => true],
            'console_synced_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'metadata'          => ['type' => 'JSONB', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['subject_type', 'subject_id']);
        $this->forge->addKey('decision');
        $this->forge->createTable('reach_approvals', true);

        $this->db->query(
            "ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS reach_approvals_decision_check"
        );
        $this->db->query(
            "ALTER TABLE reach_approvals ADD CONSTRAINT reach_approvals_decision_check "
            . "CHECK (decision IN ('pending','approved','rejected'))"
        );
        $this->db->query(
            "ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS reach_approvals_subject_check"
        );
        $this->db->query(
            "ALTER TABLE reach_approvals ADD CONSTRAINT reach_approvals_subject_check "
            . "CHECK (subject_type IN ('blog','campaign','social','email','whatsapp','landing','bot','other'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_approvals', true);
    }
}
