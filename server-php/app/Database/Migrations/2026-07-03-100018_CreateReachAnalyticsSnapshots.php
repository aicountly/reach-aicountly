<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachAnalyticsSnapshots extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'source'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'captured_at' => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'metrics'     => ['type' => 'JSONB', 'null' => false],
            'created_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('source');
        $this->forge->addKey('captured_at');
        $this->forge->createTable('reach_analytics_snapshots', true);

        $this->db->query(
            "ALTER TABLE reach_analytics_snapshots DROP CONSTRAINT IF EXISTS reach_analytics_source_check"
        );
        $this->db->query(
            "ALTER TABLE reach_analytics_snapshots ADD CONSTRAINT reach_analytics_source_check "
            . "CHECK (source IN ('internal','ga4','gsc','meta','linkedin','twitter','youtube','email','whatsapp'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_analytics_snapshots', true);
    }
}
