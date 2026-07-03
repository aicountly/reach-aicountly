<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachLandingPages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGSERIAL'],
            'campaign_id'  => ['type' => 'BIGINT', 'null' => true],
            'slug'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'meta'         => ['type' => 'JSONB', 'null' => true],
            'body'         => ['type' => 'TEXT', 'null' => false, 'default' => ''],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'published_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_by'   => ['type' => 'BIGINT', 'null' => true],
            'created_at'   => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'   => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_landing_pages', true);

        $this->db->query(
            "ALTER TABLE reach_landing_pages DROP CONSTRAINT IF EXISTS reach_landing_pages_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_landing_pages ADD CONSTRAINT reach_landing_pages_status_check "
            . "CHECK (status IN ('draft','pending_approval','approved','published','archived'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_landing_pages', true);
    }
}
