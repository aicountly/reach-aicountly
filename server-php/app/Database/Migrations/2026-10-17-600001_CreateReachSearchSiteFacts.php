<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Site-wide Search Console storage.
 *
 * reach_search_metric_facts only keeps rows whose page maps to a Reach content
 * identity (published blogs), so the homepage, product, pricing and guide
 * pages — most of a property's traffic — were discarded and no site-wide
 * total could ever be shown. These tables keep everything Google returns:
 *
 * - reach_search_site_facts: every (date, page, query) row, unfiltered.
 *   Dimension columns are NOT NULL with sentinels so the unique key matches
 *   on re-ingest (two NULLs never compare equal in Postgres).
 * - reach_search_site_daily: property-level totals per day and device,
 *   fetched without page/query dimensions. Google aggregates by property when
 *   neither page nor query is requested and drops anonymised queries from
 *   query-level rows, so these are the only accurate daily totals; summing
 *   reach_search_site_facts under-counts.
 */
class CreateReachSearchSiteFacts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_search_site_facts (
                id                BIGSERIAL PRIMARY KEY,
                connection_id     BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE CASCADE,
                site_property     VARCHAR(255) NOT NULL DEFAULT '',
                ingestion_run_id  BIGINT,
                metric_date       DATE NOT NULL,
                page_url          TEXT NOT NULL DEFAULT '',
                query             TEXT NOT NULL DEFAULT '',
                device            VARCHAR(20) NOT NULL DEFAULT 'UNKNOWN',
                country           VARCHAR(3) NOT NULL DEFAULT 'ZZZ',
                clicks            INT NOT NULL DEFAULT 0,
                impressions       INT NOT NULL DEFAULT 0,
                ctr               NUMERIC(8,6),
                avg_position      NUMERIC(8,2),
                collected_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_search_site_fact UNIQUE (connection_id, metric_date, page_url, query, device, country)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_search_site_facts_date ON reach_search_site_facts (metric_date DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_search_site_facts_conn_date ON reach_search_site_facts (connection_id, metric_date DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_search_site_facts_query ON reach_search_site_facts (query)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_search_site_daily (
                id                BIGSERIAL PRIMARY KEY,
                connection_id     BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE CASCADE,
                site_property     VARCHAR(255) NOT NULL DEFAULT '',
                ingestion_run_id  BIGINT,
                metric_date       DATE NOT NULL,
                device            VARCHAR(20) NOT NULL DEFAULT 'UNKNOWN',
                clicks            INT NOT NULL DEFAULT 0,
                impressions       INT NOT NULL DEFAULT 0,
                ctr               NUMERIC(8,6),
                avg_position      NUMERIC(8,2),
                collected_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_search_site_daily UNIQUE (connection_id, metric_date, device)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_search_site_daily_date ON reach_search_site_daily (metric_date DESC)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_search_site_daily');
        $this->db->query('DROP TABLE IF EXISTS reach_search_site_facts');
    }
}
