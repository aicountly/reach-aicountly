<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachBlogVersions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGSERIAL'],
            'blog_post_id'  => ['type' => 'BIGINT', 'null' => false],
            'version'       => ['type' => 'INTEGER', 'null' => false],
            'snapshot'      => ['type' => 'JSONB', 'null' => false],
            'changed_by'    => ['type' => 'BIGINT', 'null' => true],
            'change_reason' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'created_at'    => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['blog_post_id', 'version']);
        $this->forge->addForeignKey('blog_post_id', 'reach_blog_posts', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_blog_versions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_blog_versions', true);
    }
}
