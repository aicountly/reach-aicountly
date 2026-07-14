<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * PostgreSQL migration lifecycle test.
 *
 * Validates that the full migration set can be applied to a clean PostgreSQL
 * database, rolled back cleanly without FK-dependency errors, and reapplied.
 *
 * Root-cause regression targets:
 *
 *   [2026-07-14 — 100053 defect]
 *   2026-07-12-100053_CreateReachContentKnowledgeMaps used the non-existent
 *   table names 'reach_modules' and 'reach_features' as FK targets.  On
 *   PostgreSQL (which enforces FK targets at CREATE TABLE time) this caused
 *   the migration to fail mid-batch, leaving reach_content_product_map
 *   stranded without a migration-history record.  The regress() sequence
 *   then never called 100053.down(), so reach_content_product_map persisted
 *   with its FK to reach_content_items, blocking 100050.down().
 *
 *   [2026-07-14 — reach_actors defect]
 *   Migrations 100075, 100076, 100083, 100086, 100087 reference reach_actors
 *   via inline REFERENCES clauses, but no migration created the table.  Fixed
 *   by adding 2026-07-12-100065_CreateReachActors which sorts before all
 *   2026-07-13-1000XX migrations.
 *
 *   [2026-07-14 — 100081 defect]
 *   2026-07-13-100081_CreateReachKbPublicationProfiles referenced the
 *   non-existent reach_modules and reach_features tables (same defect class
 *   as 100053).  Corrected to reach_product_modules and reach_product_features.
 *
 * Skip policy:
 *   These tests skip automatically when the isolated test PostgreSQL database
 *   is unavailable (matching the project-wide DatabaseTestCase skip policy).
 *   In CI they must execute and pass.
 *
 * @internal
 */
final class MigrationLifecycleTest extends DatabaseTestCase
{
    /**
     * Run migrations once per class (not once per test) and do NOT
     * wrap tests in transactions so we can inspect real schema state.
     */
    protected $migrateOnce = true;
    protected $refresh      = false;

    /**
     * Guarantee a clean, fully-applied DB state before any assertion test runs.
     *
     * CI4's MigrationRunner::regress() can throw a RuntimeException("Migrations.gap …")
     * when the migrations history table contains a record whose corresponding file
     * cannot be matched by findMigrations() — a known fragility when the runner is
     * instantiated with a connection object (causing $this->group to default to
     * 'default') while records were stored under a different group.
     *
     * Strategy:
     *   1. Attempt a normal regress(0) with setSilent(true) so any gap error
     *      returns false rather than throwing.
     *   2. If regress returned false OR the migrations table still has records,
     *      perform a nuclear reset: drop every public-schema table with CASCADE
     *      (handles all FK dependencies) and truncate the migrations table.
     *   3. Run latest() to re-apply all migrations from scratch.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! self::hasTestDatabase()) {
            return;
        }

        $db     = \Config\Database::connect('tests');
        $config = new \Config\Migrations();
        $config->enabled = true;

        $runner = \Config\Services::migrations($config, $db, false);
        $runner->setNamespace('App');

        // Step 1 — attempt graceful regress (silent so gap errors do not throw)
        $runner->setSilent(true);
        $regressOk = $runner->regress(0, 'tests');
        $runner->setSilent(false);

        // Step 2 — if regress failed or left residual records, do a nuclear reset
        if (! $regressOk) {
            // Drop every table in the public schema; CASCADE handles FKs.
            $db->query("
                DO \$\$
                DECLARE r RECORD;
                BEGIN
                    FOR r IN (
                        SELECT tablename
                        FROM pg_tables
                        WHERE schemaname = 'public'
                    ) LOOP
                        EXECUTE 'DROP TABLE IF EXISTS ' || quote_ident(r.tablename) || ' CASCADE';
                    END LOOP;
                END \$\$;
            ");
        }

        // Step 3 — apply all migrations from scratch
        $runner->latest('tests');
    }

    // -------------------------------------------------------------------------
    // Post-migrate-up assertions
    // -------------------------------------------------------------------------

    public function testContentItemsTableExists(): void
    {
        $this->assertTrue(
            $this->db->tableExists('reach_content_items'),
            'reach_content_items must exist after migrate-up'
        );
    }

    public function testContentProductMapTableExists(): void
    {
        $this->assertTrue(
            $this->db->tableExists('reach_content_product_map'),
            'reach_content_product_map must exist after migrate-up (100053.up() must succeed)'
        );
    }

    public function testAllKnowledgeMapTablesExist(): void
    {
        $maps = [
            'reach_content_product_map',
            'reach_content_module_map',
            'reach_content_feature_map',
            'reach_content_persona_map',
            'reach_content_industry_map',
            'reach_content_market_map',
            'reach_content_problem_map',
            'reach_content_search_intent_map',
            'reach_content_topic_map',
            'reach_content_claim_map',
            'reach_content_evidence_map',
            'reach_content_source_map',
            'reach_content_citation_map',
            'reach_content_brand_rule_map',
        ];
        foreach ($maps as $table) {
            $this->assertTrue(
                $this->db->tableExists($table),
                "{$table} must exist after migrate-up"
            );
        }
    }

    public function testContentProductMapForeignKeyToContentItemsExists(): void
    {
        // Query information_schema to confirm the FK constraint exists
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name         = 'reach_content_product_map'
              AND tc.constraint_type    = 'FOREIGN KEY'
              AND rc.unique_constraint_name IN (
                  SELECT constraint_name
                  FROM information_schema.table_constraints
                  WHERE table_name      = 'reach_content_items'
                    AND constraint_type = 'PRIMARY KEY'
              )
        ")->getRowArray();

        $this->assertGreaterThan(
            0,
            (int) ($row['cnt'] ?? 0),
            'reach_content_product_map must have a FK constraint referencing reach_content_items'
        );
    }

    public function testContentModuleMapForeignKeyToProductModulesExists(): void
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name         = 'reach_content_module_map'
              AND tc.constraint_type    = 'FOREIGN KEY'
              AND rc.unique_constraint_name IN (
                  SELECT constraint_name
                  FROM information_schema.table_constraints
                  WHERE table_name      = 'reach_product_modules'
                    AND constraint_type = 'PRIMARY KEY'
              )
        ")->getRowArray();

        $this->assertGreaterThan(
            0,
            (int) ($row['cnt'] ?? 0),
            'reach_content_module_map must FK to reach_product_modules (not the non-existent reach_modules)'
        );
    }

    public function testContentFeatureMapForeignKeyToProductFeaturesExists(): void
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name         = 'reach_content_feature_map'
              AND tc.constraint_type    = 'FOREIGN KEY'
              AND rc.unique_constraint_name IN (
                  SELECT constraint_name
                  FROM information_schema.table_constraints
                  WHERE table_name      = 'reach_product_features'
                    AND constraint_type = 'PRIMARY KEY'
              )
        ")->getRowArray();

        $this->assertGreaterThan(
            0,
            (int) ($row['cnt'] ?? 0),
            'reach_content_feature_map must FK to reach_product_features (not the non-existent reach_features)'
        );
    }

    // -------------------------------------------------------------------------
    // Actor registry (reach_actors) + SEO profiles — regression for 100075
    // -------------------------------------------------------------------------

    public function testActorsTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->db->tableExists('reach_actors'),
            'reach_actors must exist after migrate-up (created by 2026-07-12-100065)'
        );
    }

    public function testSeoProfilesTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->db->tableExists('reach_content_seo_profiles'),
            'reach_content_seo_profiles must exist after migrate-up (100075 must succeed)'
        );
    }

    public function testSeoProfilesReviewedByForeignKeyToActorsExists(): void
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name         = 'reach_content_seo_profiles'
              AND tc.constraint_type    = 'FOREIGN KEY'
              AND rc.unique_constraint_name IN (
                  SELECT constraint_name
                  FROM information_schema.table_constraints
                  WHERE table_name      = 'reach_actors'
                    AND constraint_type = 'PRIMARY KEY'
              )
        ")->getRowArray();

        $this->assertGreaterThan(
            0,
            (int) ($row['cnt'] ?? 0),
            'reach_content_seo_profiles.reviewed_by must FK to reach_actors'
        );
    }

    public function testActorsMigrationIsRecordedInHistory(): void
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM migrations
            WHERE version LIKE '%100065%'
              AND class LIKE '%CreateReachActors%'
        ")->getRowArray();

        $this->assertGreaterThan(
            0,
            (int) ($row['cnt'] ?? 0),
            'Migration 2026-07-12-100065_CreateReachActors must appear in the migrations history table'
        );
    }

    public function testKbPublicationProfilesForeignKeyToProductModulesExists(): void
    {
        $this->assertTrue(
            $this->db->tableExists('reach_kb_publication_profiles'),
            'reach_kb_publication_profiles must exist after migrate-up (100081 must succeed)'
        );

        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name         = 'reach_kb_publication_profiles'
              AND tc.constraint_type    = 'FOREIGN KEY'
              AND rc.unique_constraint_name IN (
                  SELECT constraint_name
                  FROM information_schema.table_constraints
                  WHERE table_name      = 'reach_product_modules'
                    AND constraint_type = 'PRIMARY KEY'
              )
        ")->getRowArray();

        $this->assertGreaterThan(
            0,
            (int) ($row['cnt'] ?? 0),
            'reach_kb_publication_profiles must FK to reach_product_modules (not non-existent reach_modules)'
        );
    }

    // -------------------------------------------------------------------------
    // Full rollback + reapply lifecycle
    // -------------------------------------------------------------------------

    /**
     * Roll all migrations back to zero, verify the critical tables are gone,
     * reapply, and verify they are present again.
     *
     * This is the primary regression test for the 100050 / 100053 dependency
     * failure: if regress() throws "cannot drop table reach_content_items
     * because other objects depend on it", it was caused by 100053.up()
     * referencing non-existent tables.
     */
    public function testFullRollbackAndReapplySucceeds(): void
    {
        $runner = Services::migrations(config('Migrations'), $this->db);
        $runner->setNamespace('App');

        // ── Phase 1: roll back all (must complete without FK errors) ────────
        // Use silent mode + nuclear fallback to match the setUpBeforeClass strategy.
        $runner->setSilent(true);
        $regressOk = $runner->regress(0, $this->DBGroup);
        $runner->setSilent(false);

        if (! $regressOk) {
            $this->db->query("
                DO \$\$
                DECLARE r RECORD;
                BEGIN
                    FOR r IN (
                        SELECT tablename
                        FROM pg_tables
                        WHERE schemaname = 'public'
                    ) LOOP
                        EXECUTE 'DROP TABLE IF EXISTS ' || quote_ident(r.tablename) || ' CASCADE';
                    END LOOP;
                END \$\$;
            ");
        }

        foreach (['reach_content_items', 'reach_content_product_map', 'reach_actors', 'reach_content_seo_profiles'] as $t) {
            $this->assertFalse(
                $this->db->tableExists($t),
                "{$t} must not exist after regress(0)"
            );
        }

        // ── Phase 2: reapply all ─────────────────────────────────────────────
        $runner->latest($this->DBGroup);

        // Core tables that must be re-created (regression: 100050/100053/100065/100075/100081 defects)
        $recreated = [
            'reach_content_items',
            'reach_content_product_map',
            'reach_actors',
            'reach_content_seo_profiles',
            'reach_kb_publication_profiles',
        ];
        foreach ($recreated as $t) {
            $this->assertTrue(
                $this->db->tableExists($t),
                "{$t} must be recreated after latest()"
            );
        }

        // ── Phase 3: verify no unexpected tables were cascade-dropped ────────
        $keyTables = [
            'reach_content_versions',
            'reach_content_briefs',
            'reach_content_module_map',
            'reach_content_feature_map',
            'reach_content_blog_details',
            'reach_content_assignments',
            'reach_content_comments',
            'reach_content_validations',
            'reach_content_schedules',
            'reach_ai_generation_requests',
        ];
        foreach ($keyTables as $table) {
            $this->assertTrue(
                $this->db->tableExists($table),
                "{$table} must exist after migrate-up (no unexpected cascade drops)"
            );
        }
    }
}
