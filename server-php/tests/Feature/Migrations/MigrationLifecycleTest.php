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
 * Root-cause regression target (2026-07-14):
 *   2026-07-12-100053_CreateReachContentKnowledgeMaps used the non-existent
 *   table names 'reach_modules' and 'reach_features' as FK targets.  On
 *   PostgreSQL (which enforces FK targets at CREATE TABLE time) this caused
 *   the migration to fail mid-batch, leaving reach_content_product_map
 *   stranded without a migration-history record.  The regress() sequence
 *   then never called 100053.down(), so reach_content_product_map persisted
 *   with its FK to reach_content_items, blocking 100050.down().
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
        $runner->setSilent(false)->setNamespace('App');

        // ── Phase 1: roll back all (must complete without FK errors) ────────
        $runner->regress(0, $this->DBGroup);

        $this->assertFalse(
            $this->db->tableExists('reach_content_items'),
            'reach_content_items must not exist after regress(0)'
        );
        $this->assertFalse(
            $this->db->tableExists('reach_content_product_map'),
            'reach_content_product_map must not exist after regress(0)'
        );

        // ── Phase 2: reapply all ─────────────────────────────────────────────
        $runner->latest($this->DBGroup);

        $this->assertTrue(
            $this->db->tableExists('reach_content_items'),
            'reach_content_items must be recreated after latest()'
        );
        $this->assertTrue(
            $this->db->tableExists('reach_content_product_map'),
            'reach_content_product_map must be recreated after latest() (100053.up() must succeed)'
        );

        // ── Phase 3: verify no unexpected tables were cascade-dropped ────────
        // All tables that were present before rollback must be present again.
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
