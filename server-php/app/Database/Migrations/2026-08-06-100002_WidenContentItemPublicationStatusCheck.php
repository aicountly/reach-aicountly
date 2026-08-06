<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * reach_content_items.publication_status was constrained to the Phase 2
 * pre-publication values only ('none', 'publication_pending',
 * 'publication_blocked', 'ready_for_publication') — publishing was not
 * implemented yet, so there was deliberately no terminal value.
 *
 * Publishing is live now: PublicationDeploymentService marks an item published
 * once the public site accepts the deployment, and the rollback service takes
 * it back down. Both need terminal values the CHECK will accept.
 */
class WidenContentItemPublicationStatusCheck extends Migration
{
    private const STATES = [
        'none',
        'publication_pending',
        'publication_blocked',
        'ready_for_publication',
        'published',
        'unpublished',
    ];

    private const NARROW = [
        'none',
        'publication_pending',
        'publication_blocked',
        'ready_for_publication',
    ];

    public function up(): void
    {
        $list = "'" . implode("','", self::STATES) . "'";

        $this->db->query('ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_publication_status_chk');
        $this->db->query(
            'ALTER TABLE reach_content_items ADD CONSTRAINT rci_publication_status_chk '
            . "CHECK (publication_status IN ({$list}))"
        );
    }

    public function down(): void
    {
        // Feature tests migrate down between runs; rows written with the new
        // terminal values would violate the narrower CHECK, so normalise first.
        $list = "'" . implode("','", self::NARROW) . "'";

        $this->db->query('ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_publication_status_chk');
        $this->db->query(
            "UPDATE reach_content_items SET publication_status = 'none' "
            . "WHERE publication_status NOT IN ({$list})"
        );
        $this->db->query(
            'ALTER TABLE reach_content_items ADD CONSTRAINT rci_publication_status_chk '
            . "CHECK (publication_status IN ({$list}))"
        );
    }
}
