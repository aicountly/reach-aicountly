<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 0 / Phase 2 bridge — canonical actor registry.
 *
 * Background
 * ----------
 * The Phase 0 actor model introduced an `actor_type` column on `reach_users`
 * (via 2026-07-12-100028_AddActorColumns) to track whether a row was created
 * by a human, system, bot, or service.  Starting with Phase 3 migrations
 * (100075+), several tables need to record *who* reviewed, created, or
 * assigned a record — using a lightweight FK to a unified actor registry.
 *
 * This migration creates that registry.  The `reach_actors` table is the
 * single source of truth for all principals (human users, system services,
 * background bots) that may appear as FK values in any reach_* table.
 *
 * Defect history
 * --------------
 * The migration was absent from the original Phase 3 batch, causing every
 * migration from 100075 onwards to fail with:
 *
 *   ERROR: relation "reach_actors" does not exist
 *
 * Fixed: 2026-07-14 — added as 2026-07-12-100065 so it sorts before all
 * 2026-07-13-1000XX migrations and is applied before any Phase 3 migration
 * that carries an FK to reach_actors.
 *
 * Timestamp choice
 * ----------------
 * Using 2026-07-12-100065 (the next available slot after the last Phase 2
 * migration, 2026-07-12-100064) guarantees lexicographic ordering before
 * 2026-07-13-100065 (the first Phase 3 migration) without renaming any
 * already-deployed migration.
 */
class CreateReachActors extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_actors (
                id            BIGSERIAL    PRIMARY KEY,
                uuid          UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                actor_type    VARCHAR(16)  NOT NULL DEFAULT 'human'
                              CHECK (actor_type IN ('human','system','bot','service')),
                display_name  VARCHAR(255),
                email         VARCHAR(320),
                is_active     BOOLEAN      NOT NULL DEFAULT true,
                created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_actors_type     ON reach_actors(actor_type)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_actors_email    ON reach_actors(email)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_actors_active   ON reach_actors(is_active)');
    }

    public function down(): void
    {
        // CASCADE removes any surviving FK constraints in referencing tables
        // (reach_content_seo_profiles, reach_content_aeo_profiles,
        //  reach_publication_deployments, reach_publication_redirects,
        //  reach_publication_refresh_reviews).  In a normal regress(0) sequence
        // those tables are already dropped by their own down() methods (they
        // carry higher migration version numbers and are rolled back first), so
        // no live FK constraints remain at this point.  CASCADE is present as a
        // defensive guard only; it does NOT drop the referencing tables.
        $this->db->query('DROP TABLE IF EXISTS reach_actors CASCADE');
    }
}
