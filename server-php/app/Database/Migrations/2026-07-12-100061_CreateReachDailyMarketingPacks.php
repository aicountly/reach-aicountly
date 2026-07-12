<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Daily marketing packs and pack items.
 *
 * A pack is generated once per day per market/language combination.
 * Items reference existing content items (or placeholder slots for missing content).
 */
class CreateReachDailyMarketingPacks extends Migration
{
    public function up(): void
    {
        // ── Daily marketing pack ─────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'uuid'              => ['type' => 'UUID', 'null' => false, 'default' => new RawSql('gen_random_uuid()')],
            'pack_date'         => ['type' => 'DATE', 'null' => false],
            'market_id'         => ['type' => 'BIGINT', 'null' => true],
            'language'          => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => false, 'default' => 'en'],
            'pack_status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'draft'],
            'admin_owner_id'    => ['type' => 'BIGINT', 'null' => true],
            'summary'           => ['type' => 'TEXT', 'null' => true],
            'config_snapshot'   => ['type' => 'JSONB', 'null' => true],
            'approved_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'       => ['type' => 'BIGINT', 'null' => true],
            // Actor
            'generated_by'      => ['type' => 'BIGINT', 'null' => true],
            'created_actor_type' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'deleted_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey(['pack_date', 'market_id', 'language']);
        $this->forge->addKey('pack_date');
        $this->forge->addKey('pack_status');
        $this->forge->addKey('deleted_at');
        $this->forge->createTable('reach_daily_marketing_packs', true);

        $this->db->query("ALTER TABLE reach_daily_marketing_packs DROP CONSTRAINT IF EXISTS rdmp_status_chk");
        $this->db->query(
            "ALTER TABLE reach_daily_marketing_packs ADD CONSTRAINT rdmp_status_chk "
            . "CHECK (pack_status IN ('draft','review','approved','locked','completed'))"
        );

        // ── Daily marketing pack items ────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'pack_id'           => ['type' => 'BIGINT', 'null' => false],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => true],
            'slot_type'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'slot_label'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'is_placeholder'    => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'priority'          => ['type' => 'SMALLINT', 'null' => false, 'default' => 3],
            'sort_order'        => ['type' => 'INT', 'null' => false, 'default' => 0],
            'reviewer_id'       => ['type' => 'BIGINT', 'null' => true],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('pack_id');
        $this->forge->addKey('content_item_id');
        $this->forge->addForeignKey('pack_id', 'reach_daily_marketing_packs', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_daily_marketing_pack_items', true);

        // Prevent the same content item from appearing twice in one pack
        $this->db->query(
            "CREATE UNIQUE INDEX IF NOT EXISTS rdmpi_unique_item_per_pack "
            . "ON reach_daily_marketing_pack_items (pack_id, content_item_id) "
            . "WHERE content_item_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_daily_marketing_pack_items', true);
        $this->forge->dropTable('reach_daily_marketing_packs', true);
    }
}
