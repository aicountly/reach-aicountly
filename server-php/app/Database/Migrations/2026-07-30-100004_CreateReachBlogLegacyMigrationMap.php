<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachBlogLegacyMigrationMap extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'legacy_blog_post_id' => ['type' => 'BIGINT', 'null' => false],
            'content_item_id'     => ['type' => 'BIGINT', 'null' => false],
            'content_version_id'  => ['type' => 'BIGINT', 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'pending'],
            'error_message'       => ['type' => 'TEXT', 'null' => true],
            'migrated_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'          => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('legacy_blog_post_id');
        $this->forge->addKey('content_item_id');
        $this->forge->addKey('status');
        $this->forge->createTable('reach_blog_legacy_migration_map', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_blog_legacy_migration_map', true);
    }
}
