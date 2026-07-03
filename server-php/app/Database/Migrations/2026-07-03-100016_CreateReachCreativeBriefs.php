<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCreativeBriefs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGSERIAL'],
            'campaign_id'    => ['type' => 'BIGINT', 'null' => true],
            'title'          => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'brief'          => ['type' => 'TEXT', 'null' => false, 'default' => ''],
            'audience'       => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'deliverables'   => ['type' => 'JSONB', 'null' => true],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'bot_generated'  => ['type' => 'BOOLEAN', 'default' => false],
            'created_by'     => ['type' => 'BIGINT', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'     => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_creative_briefs', true);

        $this->db->query(
            "ALTER TABLE reach_creative_briefs DROP CONSTRAINT IF EXISTS reach_creative_briefs_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_creative_briefs ADD CONSTRAINT reach_creative_briefs_status_check "
            . "CHECK (status IN ('draft','in_review','approved','archived'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_creative_briefs', true);
    }
}
