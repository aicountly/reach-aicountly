<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachAudienceSegmentRules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'segment_id' => ['type' => 'BIGINT', 'null' => false],
            'rule_group' => ['type' => 'INT', 'default' => 0],
            'field'      => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'operator'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'negated'    => ['type' => 'BOOLEAN', 'default' => false],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('segment_id');
        $this->forge->addForeignKey('segment_id', 'reach_audience_segments', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_audience_segment_rules', true);

        $this->db->query(
            "ALTER TABLE reach_audience_segment_rules ADD CONSTRAINT chk_segment_rules_operator "
            . "CHECK (operator IN ('eq','neq','contains','not_contains','gt','lt','gte','lte','in','not_in','is_null','is_not_null'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_audience_segment_rules', true);
    }
}
