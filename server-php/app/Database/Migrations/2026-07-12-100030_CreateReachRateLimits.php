<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Postgres-backed rate-limit counters. Chosen over Redis/file-cache because
 * the cPanel deployment target does not have Redis and file-cache is per
 * PHP-FPM worker (would silently allow N× the intended limit across workers).
 *
 * Row lifecycle: one row per (bucket_key, window_start). The RateLimitFilter
 * upserts, atomically increments, and reads the count in a single statement.
 * Rows older than `now() - retention_days` are pruned by reach:schedule.
 */
class CreateReachRateLimits extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGSERIAL'],
            'bucket_key'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'window_start' => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'tokens'       => ['type' => 'INTEGER', 'null' => false, 'default' => 0],
            'blocked_hits' => ['type' => 'INTEGER', 'null' => false, 'default' => 0],
            'updated_at'   => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['bucket_key', 'window_start']);
        $this->forge->addKey('window_start');
        $this->forge->createTable('reach_rate_limits', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_rate_limits', true);
    }
}
