<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Indexing state must be tracked independently of publish/refresh status —
 * "published" and "in the sitemap" are not the same as "indexed by Google".
 * Previously no such column existed anywhere in the schema, so
 * WorkBlockService had no real CHECK_INDEXING / UPDATE_SITEMAP handler and
 * no dashboard could distinguish "just published" from "actually indexed"
 * even in principle.
 *
 * Values are set only by WorkBlockService::executeUpdateSitemap() and
 * ::executeCheckIndexing(), which never claim INDEXED without a live signal
 * (a real Search Console Analytics hit or, at minimum, sitemap presence).
 */
class AddIndexingStatusToBlogPublicationProfiles extends Migration
{
    private const STATES = [
        'not_submitted', 'sitemap_included', 'discovered', 'crawled',
        'indexed', 'excluded', 'error', 'unknown',
    ];

    public function up(): void
    {
        $list = "'" . implode("','", self::STATES) . "'";

        $this->db->query(
            "ALTER TABLE reach_blog_publication_profiles "
            . "ADD COLUMN IF NOT EXISTS indexing_status VARCHAR(32) NOT NULL DEFAULT 'not_submitted' "
            . "CHECK (indexing_status IN ({$list}))"
        );
        $this->db->query(
            'ALTER TABLE reach_blog_publication_profiles '
            . 'ADD COLUMN IF NOT EXISTS indexing_checked_at TIMESTAMPTZ'
        );
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_pub_indexing_status ON reach_blog_publication_profiles(indexing_status)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_blog_publication_profiles DROP COLUMN IF EXISTS indexing_status');
        $this->db->query('ALTER TABLE reach_blog_publication_profiles DROP COLUMN IF EXISTS indexing_checked_at');
    }
}
