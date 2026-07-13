<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunitySourceCoverage extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_source_coverage (
                id                  BIGSERIAL PRIMARY KEY,
                answer_version_id   BIGINT NOT NULL REFERENCES reach_community_answer_versions(id) ON DELETE CASCADE,
                source_type         VARCHAR(40) NOT NULL
                                        CHECK (source_type IN (
                                            'kb_article','blog','product_doc','release_note',
                                            'policy','feature_record','marketing_knowledge','external'
                                        )),
                source_id           BIGINT,
                source_uuid         UUID,
                source_title        VARCHAR(512),
                source_version      VARCHAR(80),
                source_url          TEXT,
                claim_reference     TEXT,
                coverage_status     VARCHAR(20) NOT NULL DEFAULT 'covered'
                                        CHECK (coverage_status IN (
                                            'covered','partial','missing','conflicted'
                                        )),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcsc_version ON reach_community_source_coverage(answer_version_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcsc_status ON reach_community_source_coverage(coverage_status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_source_coverage CASCADE');
    }
}
