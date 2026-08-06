<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Make reach_search_metric_facts actually upsertable against live Search
 * Console data.
 *
 * Three defects blocked real ingestion:
 *
 * 1. `country CHAR(2)` cannot store what the API returns. Search Console
 *    reports countries as ISO-3166-1 alpha-3 ("ind", "usa"), so every row
 *    carrying a country dimension would have been rejected outright.
 *
 * 2. `query`, `device` and `country` were nullable, and they are all part of
 *    uq_search_metric_fact. In Postgres two NULLs are never equal, so the
 *    unique constraint silently stopped matching whenever a dimension was
 *    absent — ON CONFLICT would never fire and each re-ingest of the same day
 *    appended a duplicate row instead of restating the existing one.
 *
 * 3. `metric_date` had no per-connection retention story; unchanged here, but
 *    the composite index below makes the date-range reads the API now serves
 *    cheap enough to run on every dashboard load.
 *
 * Existing rows are normalised to the same sentinels the connector emits so
 * the constraint is satisfiable before it is tightened.
 */
class NormaliseSearchMetricFactKeys extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN country TYPE VARCHAR(3)");

        // Order matters. uq_search_metric_fact is not deferrable, so the
        // duplicates have to go BEFORE the sentinels are written: the moment two
        // rows that differed only by a NULL both become 'ZZZ' they collide, and
        // the UPDATE itself aborts. Deduplicate first, comparing NULL-safely
        // (IS NOT DISTINCT FROM) because that is exactly the equality the broken
        // constraint failed to enforce.
        $this->db->query("
            DELETE FROM reach_search_metric_facts a
            USING reach_search_metric_facts b
            WHERE a.id < b.id
              AND a.content_identity_id = b.content_identity_id
              AND a.connection_id       = b.connection_id
              AND a.metric_date         = b.metric_date
              AND a.page_url            = b.page_url
              AND a.query   IS NOT DISTINCT FROM b.query
              AND a.device  IS NOT DISTINCT FROM b.device
              AND upper(a.country) IS NOT DISTINCT FROM upper(b.country)
        ");

        // A NULL and a written-out sentinel are the same logical row too, so
        // collapse those pairs before the sentinels are applied.
        $this->db->query("
            DELETE FROM reach_search_metric_facts a
            USING reach_search_metric_facts b
            WHERE a.id < b.id
              AND a.content_identity_id = b.content_identity_id
              AND a.connection_id       = b.connection_id
              AND a.metric_date         = b.metric_date
              AND a.page_url            = b.page_url
              AND COALESCE(a.query, '')              = COALESCE(b.query, '')
              AND COALESCE(a.device, 'UNKNOWN')      = COALESCE(b.device, 'UNKNOWN')
              AND COALESCE(NULLIF(upper(btrim(a.country)), ''), 'ZZZ')
                = COALESCE(NULLIF(upper(btrim(b.country)), ''), 'ZZZ')
        ");

        $this->db->query("UPDATE reach_search_metric_facts SET query = '' WHERE query IS NULL");
        $this->db->query("UPDATE reach_search_metric_facts SET device = 'UNKNOWN' WHERE device IS NULL");
        $this->db->query("UPDATE reach_search_metric_facts SET country = 'ZZZ' WHERE country IS NULL OR btrim(country) = ''");
        $this->db->query("UPDATE reach_search_metric_facts SET country = upper(country) WHERE country <> upper(country)");

        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN query   SET DEFAULT ''");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN device  SET DEFAULT 'UNKNOWN'");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN country SET DEFAULT 'ZZZ'");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN query   SET NOT NULL");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN device  SET NOT NULL");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN country SET NOT NULL");

        $this->db->query("
            CREATE INDEX IF NOT EXISTS idx_search_facts_date_query
                ON reach_search_metric_facts (metric_date DESC, query)
        ");
        $this->db->query("
            CREATE INDEX IF NOT EXISTS idx_search_facts_date_page
                ON reach_search_metric_facts (metric_date DESC, page_url)
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP INDEX IF EXISTS idx_search_facts_date_page");
        $this->db->query("DROP INDEX IF EXISTS idx_search_facts_date_query");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN query   DROP NOT NULL");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN device  DROP NOT NULL");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN country DROP NOT NULL");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN query   DROP DEFAULT");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN device  DROP DEFAULT");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN country DROP DEFAULT");
        $this->db->query("ALTER TABLE reach_search_metric_facts ALTER COLUMN country TYPE CHAR(2) USING left(country, 2)");
    }
}
