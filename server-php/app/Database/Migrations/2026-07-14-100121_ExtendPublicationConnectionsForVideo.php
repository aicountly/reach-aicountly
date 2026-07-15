<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 6 extension — reach_publication_connections.
 *
 * Phase 4 created reach_publication_connections with blog/KB auth types and no
 * connection_type column.  Phase 6 YouTube publishing requires:
 *   - authentication_type: 'oauth2' (plus existing hmac_bearer/bearer_only)
 *   - connection_type: 'youtube' (new column)
 *
 * This migration widens the CHECK constraints via DROP + ADD.  PostgreSQL
 * does not support ALTER TABLE ... MODIFY CONSTRAINT, so this is the
 * standard approach.
 *
 * The migration is safe to revert — down() restores the original CHECK
 * values and any rows with 'oauth2' / 'youtube' values are expected to be
 * removed before rollback in production, or absent in test environments.
 */
class ExtendPublicationConnectionsForVideo extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE reach_publication_connections
                DROP CONSTRAINT IF EXISTS reach_publication_connections_authentication_type_check
        ");
        $this->db->query("
            ALTER TABLE reach_publication_connections
                ADD CONSTRAINT reach_publication_connections_authentication_type_check
                CHECK (authentication_type IN ('hmac_bearer','bearer_only','none'))
        ");

        $this->db->query("
            ALTER TABLE reach_publication_connections
                DROP CONSTRAINT IF EXISTS reach_publication_connections_connection_type_check
        ");
        $this->db->query('ALTER TABLE reach_publication_connections DROP COLUMN IF EXISTS connection_type');
    }
}
