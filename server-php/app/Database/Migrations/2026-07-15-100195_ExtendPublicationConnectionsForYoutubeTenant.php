<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 6 CP8 follow-up — YouTube connection rows on reach_publication_connections.
 *
 * Phase 4 created a global publishing-connector schema (connection_key, display_name,
 * base_url, enabled). Phase 6's earlier extension only added connection_type + oauth2.
 * VideoConnectionService also needs tenant scoping, uuid API keys, and a credentials
 * envelope for OAuth2 token references.
 */
class ExtendPublicationConnectionsForYoutubeTenant extends Migration
{
    public function up(): void
    {
        // Ensure Phase 6 connection_type / oauth2 widening is present (idempotent).
        $this->db->query("
            ALTER TABLE reach_publication_connections
                DROP CONSTRAINT IF EXISTS reach_publication_connections_authentication_type_check
        ");
        $this->db->query("
            ALTER TABLE reach_publication_connections
                ADD CONSTRAINT reach_publication_connections_authentication_type_check
                CHECK (authentication_type IN (
                    'hmac_bearer','bearer_only','api_key','bearer_token','oauth2','basic','none'
                ))
        ");
        $this->db->query("
            ALTER TABLE reach_publication_connections
                ADD COLUMN IF NOT EXISTS connection_type VARCHAR(32) NOT NULL DEFAULT 'custom'
        ");
        $this->db->query("
            ALTER TABLE reach_publication_connections
                DROP CONSTRAINT IF EXISTS reach_publication_connections_connection_type_check
        ");
        $this->db->query("
            ALTER TABLE reach_publication_connections
                ADD CONSTRAINT reach_publication_connections_connection_type_check
                CHECK (connection_type IN ('wordpress','ghost','hashnode','medium','devto','youtube','custom'))
        ");

        $this->db->query('
            ALTER TABLE reach_publication_connections
                ADD COLUMN IF NOT EXISTS uuid UUID UNIQUE DEFAULT gen_random_uuid()
        ');
        $this->db->query('
            ALTER TABLE reach_publication_connections
                ADD COLUMN IF NOT EXISTS tenant_id BIGINT NULL
        ');
        $this->db->query('
            ALTER TABLE reach_publication_connections
                ADD COLUMN IF NOT EXISTS credentials JSONB NULL
        ');
        $this->db->query('
            ALTER TABLE reach_publication_connections
                ADD COLUMN IF NOT EXISTS created_by BIGINT NULL
                    REFERENCES reach_actors(id) ON DELETE SET NULL
        ');

        // YouTube OAuth connections do not use a public-site base URL.
        $this->db->query('
            ALTER TABLE reach_publication_connections
                ALTER COLUMN base_url DROP NOT NULL
        ');

        $this->db->query('
            CREATE INDEX IF NOT EXISTS idx_pub_connections_tenant_type
                ON reach_publication_connections (tenant_id, connection_type)
                WHERE tenant_id IS NOT NULL
        ');

        // Backfill uuids for any pre-existing publishing rows.
        $this->db->query('
            UPDATE reach_publication_connections
               SET uuid = gen_random_uuid()
             WHERE uuid IS NULL
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_pub_connections_tenant_type');
        $this->db->query('ALTER TABLE reach_publication_connections DROP COLUMN IF EXISTS created_by');
        $this->db->query('ALTER TABLE reach_publication_connections DROP COLUMN IF EXISTS credentials');
        $this->db->query('ALTER TABLE reach_publication_connections DROP COLUMN IF EXISTS tenant_id');
        $this->db->query('ALTER TABLE reach_publication_connections DROP COLUMN IF EXISTS uuid');
        // Do not re-add NOT NULL on base_url — existing null YouTube rows would block rollback.
    }
}
