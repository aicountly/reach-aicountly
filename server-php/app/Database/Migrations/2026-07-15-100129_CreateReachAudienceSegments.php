<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachAudienceSegments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGSERIAL'],
            'uuid'             => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'tenant_id'        => ['type' => 'BIGINT', 'null' => false],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'description'      => ['type' => 'TEXT', 'null' => true],
            'segment_type'     => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'dynamic'],
            'criteria_summary' => ['type' => 'TEXT', 'null' => true],
            'estimated_count'  => ['type' => 'INT', 'default' => 0],
            'is_active'        => ['type' => 'BOOLEAN', 'default' => true],
            'created_by'       => ['type' => 'BIGINT', 'null' => true],
            'created_at'       => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'       => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('reach_audience_segments', true);

        $this->db->query(
            "ALTER TABLE reach_audience_segments ADD CONSTRAINT chk_segments_type "
            . "CHECK (segment_type IN ('static','dynamic'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_audience_segments', true);
    }
}
