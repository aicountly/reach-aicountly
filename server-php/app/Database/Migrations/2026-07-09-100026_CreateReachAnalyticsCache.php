<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachAnalyticsCache extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'report_key'  => ['type' => 'VARCHAR', 'constraint' => 64],
            'params_hash' => ['type' => 'VARCHAR', 'constraint' => 32],
            'data_json'   => ['type' => 'JSONB', 'null' => false, 'default' => new RawSql("'{}'::jsonb")],
            'fetched_at'  => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'expires_at'  => ['type' => 'TIMESTAMPTZ', 'null' => false],
        ]);
        $this->forge->addPrimaryKey(['report_key', 'params_hash']);
        $this->forge->addKey('expires_at');
        $this->forge->createTable('reach_analytics_cache', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_analytics_cache', true);
    }
}
