<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 6 extension — reach_publication_connections.
 *
 * Phase 4 created reach_publication_connections with a narrow set of
 * authentication_type and connection_type CHECK values intended for blog/KB
 * publishing.  Phase 6 YouTube publishing requires:
 *   - authentication_type: 'oauth2'
 *   - connection_type: 'youtube'
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
                CHECK (authentication_type IN ('api_key','bearer_token','oauth2','basic','none'))
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
                CHECK (authentication_type IN ('api_key','bearer_token','basic','none'))
        ");

        $this->db->query("
            ALTER TABLE reach_publication_connections
                DROP CONSTRAINT IF EXISTS reach_publication_connections_connection_type_check
        ");
        $this->db->query("
            ALTER TABLE reach_publication_connections
                ADD CONSTRAINT reach_publication_connections_connection_type_check
                CHECK (connection_type IN ('wordpress','ghost','hashnode','medium','devto','custom'))
        ");
    }
}
