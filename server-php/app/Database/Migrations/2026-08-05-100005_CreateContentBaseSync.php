<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Content base lives in the repo (server-php/content-base/**, deployed with
 * the API). reach:content-base-sync upserts blog index entries into
 * reach_topic_candidates keyed by content_base_key; sync runs are audited so
 * the console can show file → DB drift.
 */
class CreateContentBaseSync extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_topic_candidates ADD COLUMN IF NOT EXISTS content_base_key VARCHAR(120)');
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_topic_candidates_base_key ON reach_topic_candidates(content_base_key) WHERE content_base_key IS NOT NULL');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_base_sync_runs (
                id             BIGSERIAL PRIMARY KEY,
                file_key       VARCHAR(64) NOT NULL,
                file_checksum  VARCHAR(64) NOT NULL,
                entries_seen   INT NOT NULL DEFAULT 0,
                created_count  INT NOT NULL DEFAULT 0,
                updated_count  INT NOT NULL DEFAULT 0,
                retired_count  INT NOT NULL DEFAULT 0,
                ran_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_content_base_sync_runs_key ON reach_content_base_sync_runs(file_key, ran_at)');
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_base_sync_runs', true);
        $this->db->query('DROP INDEX IF EXISTS uq_topic_candidates_base_key');
        $this->db->query('ALTER TABLE reach_topic_candidates DROP COLUMN IF EXISTS content_base_key');
    }
}
