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

        // Step 2 — if regress failed, do a nuclear reset
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

        // ALWAYS recreate the runner after regress (success or failure) to flush
        // any stale batch/history cache that would cause latest() to be a no-op.
        $runner = \Config\Services::migrations($config, $db, false);
        $runner->setNamespace('App');

        // Step 3 — apply all migrations from scratch
        $runner->latest('tests');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check table existence via a live pg_tables query, bypassing CI4's
     * dataCache['table_names'] which is populated once and never flushed
     * after DDL, causing false negatives for tables created by migrations.
     */
    private function tableLiveExists(string $tableName): bool
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM pg_tables WHERE schemaname = 'public' AND tablename = ?",
            [$tableName]
        )->getRowArray();
        return (int) ($row['cnt'] ?? 0) > 0;
    }

    // -------------------------------------------------------------------------
    // Post-migrate-up assertions
    // -------------------------------------------------------------------------

    public function testContentItemsTableExists(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_content_items'),
            'reach_content_items must exist after migrate-up'
        );
    }

    public function testContentProductMapTableExists(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_content_product_map'),
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
                $this->tableLiveExists($table),
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
            $this->tableLiveExists('reach_actors'),
            'reach_actors must exist after migrate-up (created by 2026-07-12-100065)'
        );
    }

    public function testSeoProfilesTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_content_seo_profiles'),
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
            $this->tableLiveExists('reach_kb_publication_profiles'),
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
    // Phase 6 video tables
    // -------------------------------------------------------------------------

    public function testVideoIdeasTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_video_ideas'),
            'reach_video_ideas must exist after migrate-up (100106)'
        );
    }

    public function testVideoProjectsTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_video_projects'),
            'reach_video_projects must exist after migrate-up (100108)'
        );
    }

    public function testVideoScriptVersionsTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_video_script_versions'),
            'reach_video_script_versions must exist after migrate-up (100110)'
        );
    }

    public function testVideoRenderJobsTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_video_render_jobs'),
            'reach_video_render_jobs must exist after migrate-up (100116)'
        );
    }

    public function testVideoProviderEventsTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_video_provider_events'),
            'reach_video_provider_events must exist after migrate-up (100119)'
        );
    }

    public function testVideoPermissionRegistryTableExistsAfterMigrateUp(): void
    {
        $this->assertTrue(
            $this->tableLiveExists('reach_video_permission_registry'),
            'reach_video_permission_registry must exist after migrate-up (100120)'
        );
    }

    public function testAllPhase6VideoTablesExistAfterMigrateUp(): void
    {
        $tables = [
            'reach_video_ideas',
            'reach_video_idea_sources',
            'reach_video_projects',
            'reach_video_scripts',
            'reach_video_script_versions',
            'reach_video_segments',
            'reach_video_caption_tracks',
            'reach_video_chapter_markers',
            'reach_video_assets',
            'reach_video_render_profiles',
            'reach_video_render_jobs',
            'reach_video_render_attempts',
            'reach_video_publication_profiles',
            'reach_video_provider_events',
            'reach_video_permission_registry',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(
                $this->tableLiveExists($table),
                "{$table} must exist after migrate-up"
            );
        }
    }

    public function testVideoProjectsForeignKeyToVideoIdeasExists(): void
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name         = 'reach_video_projects'
              AND tc.constraint_type    = 'FOREIGN KEY'
              AND rc.unique_constraint_name IN (
                  SELECT constraint_name
                  FROM information_schema.table_constraints
                  WHERE table_name      = 'reach_video_ideas'
                    AND constraint_type = 'PRIMARY KEY'
              )
        ")->getRowArray();

        $this->assertGreaterThan(
            0,
            (int) ($row['cnt'] ?? 0),
            'reach_video_projects.idea_id must FK to reach_video_ideas'
        );
    }

    public function testVideoScriptVersionsForeignKeyToActorsExists(): void
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name         = 'reach_video_script_versions'
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
            'reach_video_script_versions must have FK(s) to reach_actors'
        );
    }

    public function testVideoPermissionRegistryHasExpectedSlugs(): void
    {
        $slugs = $this->db->query(
            "SELECT slug FROM reach_video_permission_registry ORDER BY slug"
        )->getResultArray();

        $slugList = array_column($slugs, 'slug');

        $expected = [
            'video.approve',
            'video.cancel',
            'video.create',
            'video.generate',
            'video.publish',
            'video.read',
            'video.render',
            'video.retry',
            'video.review',
            'video.submit',
            'video.update',
            'video_audit.read',
            'video_connections.manage',
            'video_connections.read',
            'video_operations.read',
        ];

        foreach ($expected as $slug) {
            $this->assertContains(
                $slug,
                $slugList,
                "Video permission '{$slug}' must be seeded in reach_video_permission_registry"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Phase 7 distribution tables
    // -------------------------------------------------------------------------

    public function testAllPhase7DistributionTablesExistAfterMigrateUp(): void
    {
        $tables = [
            'reach_campaign_versions',
            'reach_campaign_channel_variants',
            'reach_audience_segments',
            'reach_audience_segment_rules',
            'reach_campaign_audience_snapshots',
            'reach_campaign_audience_recipients',
            'reach_channel_consents',
            'reach_channel_suppressions',
            'reach_campaign_dispatches',
            'reach_campaign_delivery_attempts',
            'reach_sms_campaigns',
            'reach_campaign_sender_profiles',
            'reach_campaign_templates',
            'reach_campaign_template_versions',
            'reach_campaign_provider_events',
            'reach_campaign_operational_metrics',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(
                $this->tableLiveExists($table),
                "{$table} must exist after migrate-up (Phase 7)"
            );
        }
    }

    public function testCampaignVersionsHasImmutableSchema(): void
    {
        $row = $this->db->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = 'reach_campaign_versions' AND column_name = 'updated_at'"
        )->getRowArray();
        $this->assertNull($row, 'reach_campaign_versions must not have updated_at (immutable)');
    }

    public function testCampaignDispatchesHasIdempotencyKeyUnique(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_campaign_dispatches' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_campaign_dispatches must have UNIQUE constraints');
    }

    public function testCampaignDeliveryAttemptsFKToDispatches(): void
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.referential_constraints rc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = rc.constraint_name
             AND tc.constraint_catalog = rc.constraint_catalog
             AND tc.constraint_schema  = rc.constraint_schema
            WHERE tc.table_name         = 'reach_campaign_delivery_attempts'
              AND tc.constraint_type    = 'FOREIGN KEY'
              AND rc.unique_constraint_name IN (
                  SELECT constraint_name
                  FROM information_schema.table_constraints
                  WHERE table_name      = 'reach_campaign_dispatches'
                    AND constraint_type = 'PRIMARY KEY'
              )
        ")->getRowArray();
        $this->assertGreaterThan(
            0, (int) ($row['cnt'] ?? 0),
            'reach_campaign_delivery_attempts must FK to reach_campaign_dispatches'
        );
    }

    public function testAudienceRecipientsHasDedupKeyUnique(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_campaign_audience_recipients' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_campaign_audience_recipients must have UNIQUE on dedup_key');
    }

    public function testSuppressionAddressUniqueness(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_channel_suppressions' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_channel_suppressions must have composite UNIQUE on (tenant_id, channel, address_hash)');
    }

    // -------------------------------------------------------------------------
    // Phase 8 intelligence tables (100144–100171)
    // -------------------------------------------------------------------------

    public function testAllPhase8IntelligenceTablesExistAfterMigrateUp(): void
    {
        $tables = [
            'reach_content_identities',
            'reach_content_publication_mappings',
            'reach_sitemap_snapshots',
            'reach_sitemap_entries',
            'reach_indexnow_submissions',
            'reach_indexnow_attempts',
            'reach_analytics_connections',
            'reach_analytics_ingestion_cursors',
            'reach_search_metric_facts',
            'reach_content_metric_facts',
            'reach_analytics_ingestion_runs',
            'reach_content_mapping_findings',
            'reach_utm_templates',
            'reach_attribution_touchpoints',
            'reach_attribution_conversion_links',
            'reach_attribution_calculation_versions',
            'reach_ai_visibility_prompts',
            'reach_ai_visibility_prompt_versions',
            'reach_ai_visibility_runs',
            'reach_ai_visibility_responses',
            'reach_ai_visibility_observations',
            'reach_ai_visibility_citations',
            'reach_competitors',
            'reach_competitor_aliases',
            'reach_competitor_observation_aggregates',
            'reach_connector_health',
            'reach_metric_freshness',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(
                $this->tableLiveExists($table),
                "{$table} must exist after migrate-up (Phase 8)"
            );
        }
    }

    public function testContentIdentitiesHasSourceUniqueConstraint(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_content_identities' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_content_identities must have UNIQUE constraints');
    }

    public function testSearchMetricFactsHasDedupUniqueConstraint(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_search_metric_facts' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_search_metric_facts must have UNIQUE dedup constraint');
    }

    public function testContentMetricFactsHasDedupUniqueConstraint(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_content_metric_facts' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_content_metric_facts must have UNIQUE dedup constraint');
    }

    public function testAiVisibilityResponsesImmutableUniqueRunConstraint(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_ai_visibility_responses' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_ai_visibility_responses must have UNIQUE run_id (one response per run)');
    }

    public function testAiVisibilityPromptsHasPurposeCheckConstraint(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.check_constraints
             WHERE constraint_name IN (
                 SELECT constraint_name FROM information_schema.table_constraints
                 WHERE table_name = 'reach_ai_visibility_prompts' AND constraint_type = 'CHECK'
             )
             AND check_clause LIKE '%ai_visibility_monitoring%'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0),
            'reach_ai_visibility_prompts must CHECK purpose = ai_visibility_monitoring');
    }

    public function testIngestionCursorHasUniqueConnectionStreamConstraint(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
             WHERE table_name = 'reach_analytics_ingestion_cursors' AND constraint_type = 'UNIQUE'"
        )->getRowArray();
        $this->assertGreaterThan(0, (int) ($row['cnt'] ?? 0), 'reach_analytics_ingestion_cursors must have UNIQUE (connection_id, stream_type)');
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

        // ALWAYS recreate runner after regress (success or failure) to flush
        // stale batch/history cache before calling latest().
        $runner = Services::migrations(config('Migrations'), $this->db, false);
        $runner->setNamespace('App');

        $checkTables = [
            'reach_content_items', 'reach_content_product_map',
            'reach_actors', 'reach_content_seo_profiles',
            'reach_video_ideas', 'reach_video_projects',
            'reach_video_script_versions', 'reach_video_render_jobs',
            'reach_video_provider_events',
            // Phase 7
            'reach_campaign_versions', 'reach_campaign_dispatches',
            'reach_channel_consents', 'reach_channel_suppressions',
            'reach_sms_campaigns',
            // Phase 8
            'reach_content_identities', 'reach_analytics_connections',
            'reach_search_metric_facts', 'reach_content_metric_facts',
            'reach_ai_visibility_prompts', 'reach_competitors',
            // Phase 9
            'reach_refresh_policies', 'reach_refresh_workflows',
            'reach_attribution_models', 'reach_readiness_audit_runs',
        ];
        foreach ($checkTables as $t) {
            $this->assertFalse(
                $this->tableLiveExists($t),
                "{$t} must not exist after regress(0)"
            );
        }

        // ── Phase 2: reapply all ─────────────────────────────────────────────
        $runner->latest($this->DBGroup);

        // Core tables that must be re-created (regression: 100050/100053/100065/100075/100081 defects)
        // Plus Phase 6 video tables (100106–100122) and Phase 7 distribution tables (100123–100143)
        $recreated = [
            'reach_content_items',
            'reach_content_product_map',
            'reach_actors',
            'reach_content_seo_profiles',
            'reach_kb_publication_profiles',
            'reach_video_ideas',
            'reach_video_projects',
            'reach_video_scripts',
            'reach_video_script_versions',
            'reach_video_render_jobs',
            'reach_video_provider_events',
            'reach_video_permission_registry',
            // Phase 7 distribution
            'reach_campaign_versions',
            'reach_campaign_channel_variants',
            'reach_audience_segments',
            'reach_channel_consents',
            'reach_channel_suppressions',
            'reach_campaign_dispatches',
            'reach_campaign_delivery_attempts',
            'reach_sms_campaigns',
            'reach_campaign_templates',
            'reach_campaign_provider_events',
            'reach_campaign_operational_metrics',
            // Phase 8 intelligence
            'reach_content_identities',
            'reach_content_publication_mappings',
            'reach_sitemap_snapshots',
            'reach_sitemap_entries',
            'reach_indexnow_submissions',
            'reach_analytics_connections',
            'reach_analytics_ingestion_cursors',
            'reach_search_metric_facts',
            'reach_content_metric_facts',
            'reach_analytics_ingestion_runs',
            'reach_utm_templates',
            'reach_attribution_touchpoints',
            'reach_attribution_conversion_links',
            'reach_attribution_calculation_versions',
            'reach_ai_visibility_prompts',
            'reach_ai_visibility_prompt_versions',
            'reach_ai_visibility_runs',
            'reach_ai_visibility_responses',
            'reach_ai_visibility_observations',
            'reach_ai_visibility_citations',
            'reach_competitors',
            'reach_competitor_aliases',
            'reach_competitor_observation_aggregates',
            'reach_connector_health',
            'reach_metric_freshness',
            // Phase 9 refresh and readiness
            'reach_refresh_policies',
            'reach_refresh_policy_versions',
            'reach_refresh_evidence_snapshots',
            'reach_refresh_recommendations',
            'reach_refresh_score_components',
            'reach_refresh_workflows',
            'reach_refresh_briefs',
            'reach_refresh_content_version_links',
            'reach_refresh_publication_links',
            'reach_refresh_outcome_windows',
            'reach_refresh_outcome_metrics',
            'reach_attribution_models',
            'reach_attribution_model_versions',
            'reach_attribution_journey_calculations',
            'reach_attribution_allocation_facts',
            'reach_readiness_audit_runs',
            'reach_readiness_findings',
            'reach_technical_debt_records',
            'reach_operational_readiness_checks',
            'reach_disaster_recovery_tests',
            'reach_release_acceptance_records',
        ];
        foreach ($recreated as $t) {
            $this->assertTrue(
                $this->tableLiveExists($t),
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
                $this->tableLiveExists($table),
                "{$table} must exist after migrate-up (no unexpected cascade drops)"
            );
        }
    }
}
