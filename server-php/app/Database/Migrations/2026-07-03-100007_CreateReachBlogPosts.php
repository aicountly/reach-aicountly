<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachBlogPosts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'title'             => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'slug'              => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => false],
            'excerpt'           => ['type' => 'TEXT', 'null' => true],
            'content'           => ['type' => 'TEXT', 'null' => false, 'default' => ''],
            'category'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'tags'              => ['type' => 'JSONB', 'null' => true],
            'seo_title'         => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'seo_description'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'canonical_url'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'focus_keyword'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'author'            => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'featured_image'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'scheduled_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'published_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approval_status'   => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'not_required'],
            'bot_generated'     => ['type' => 'BOOLEAN', 'default' => false],
            'current_version'   => ['type' => 'INTEGER', 'default' => 1],
            'publishing_status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'none'],
            'publishing_error'  => ['type' => 'TEXT', 'null' => true],
            'external_post_id'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'approved_by'       => ['type' => 'BIGINT', 'null' => true],
            'approved_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->addKey('approval_status');
        $this->forge->addKey('publishing_status');
        $this->forge->addKey('scheduled_at');
        $this->forge->addKey('published_at');
        $this->forge->addKey('bot_generated');
        $this->forge->createTable('reach_blog_posts', true);

        $this->db->query(
            "ALTER TABLE reach_blog_posts DROP CONSTRAINT IF EXISTS reach_blog_posts_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_blog_posts ADD CONSTRAINT reach_blog_posts_status_check "
            . "CHECK (status IN ('idea','draft','seo_review','internal_review','approved','scheduled','published','rejected','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_blog_posts DROP CONSTRAINT IF EXISTS reach_blog_posts_approval_check"
        );
        $this->db->query(
            "ALTER TABLE reach_blog_posts ADD CONSTRAINT reach_blog_posts_approval_check "
            . "CHECK (approval_status IN ('not_required','pending','approved','rejected'))"
        );
        $this->db->query(
            "ALTER TABLE reach_blog_posts DROP CONSTRAINT IF EXISTS reach_blog_posts_publishing_check"
        );
        $this->db->query(
            "ALTER TABLE reach_blog_posts ADD CONSTRAINT reach_blog_posts_publishing_check "
            . "CHECK (publishing_status IN ('none','pending_publishing','publishing','published','failed'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_blog_posts', true);
    }
}
