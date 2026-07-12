<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Content-type extension tables.
 *
 * Each table has a 1:1 relationship with reach_content_items via content_item_id
 * (UNIQUE FK). Only the extension table for the matching content_type is populated.
 */
class CreateReachContentTypeDetails extends Migration
{
    public function up(): void
    {
        // ── Blog ────────────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'seo_title'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'meta_description'  => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'canonical_url'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'estimated_read_minutes' => ['type' => 'SMALLINT', 'null' => true],
            'has_table_of_contents'  => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'schema_markup'     => ['type' => 'JSONB', 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_blog_details', true);

        // ── Knowledge Base ───────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'article_type'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'help_category'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'difficulty_level'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'related_article_ids' => ['type' => 'JSONB', 'null' => true],
            'applies_to_versions' => ['type' => 'JSONB', 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_knowledge_base_details', true);

        // ── Community Question/Answer ────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'community_type'    => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'question'],
            'answer_for_id'     => ['type' => 'BIGINT', 'null' => true],
            'forum_category'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'upvote_count'      => ['type' => 'INT', 'null' => false, 'default' => 0],
            'is_accepted_answer' => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_community_details', true);

        // ── Video ────────────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'video_type'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'duration_seconds'  => ['type' => 'INT', 'null' => true],
            'thumbnail_url'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'video_url'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'speaker_ids'       => ['type' => 'JSONB', 'null' => true],
            'chapters'          => ['type' => 'JSONB', 'null' => true],
            'transcript_available' => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_video_details', true);

        // ── Social Post ──────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'social_platform'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'character_limit'   => ['type' => 'INT', 'null' => true],
            'hashtags'          => ['type' => 'JSONB', 'null' => true],
            'media_urls'        => ['type' => 'JSONB', 'null' => true],
            'thread_position'   => ['type' => 'SMALLINT', 'null' => true],
            'is_thread_root'    => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_social_details', true);

        // ── Email ────────────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'subject_line'      => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'preheader'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'from_name'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'reply_to'          => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'template_id'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'campaign_type'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'segment_ids'       => ['type' => 'JSONB', 'null' => true],
            'personalization_tokens' => ['type' => 'JSONB', 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_email_details', true);

        // ── WhatsApp / SMS Message ───────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'message_type'      => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'whatsapp'],
            'template_name'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'max_characters'    => ['type' => 'INT', 'null' => true],
            'has_media'         => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            'media_type'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'buttons'           => ['type' => 'JSONB', 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_message_details', true);

        // ── Landing Page ─────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'content_item_id'   => ['type' => 'BIGINT', 'null' => false],
            'page_type'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'hero_headline'     => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'sub_headline'      => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'primary_cta_text'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'primary_cta_url'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sections'          => ['type' => 'JSONB', 'null' => true],
            'seo_title'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'meta_description'  => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'conversion_goal'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'updated_by'        => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'        => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('content_item_id');
        $this->forge->addForeignKey('content_item_id', 'reach_content_items', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_content_landing_details', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_landing_details', true);
        $this->forge->dropTable('reach_content_message_details', true);
        $this->forge->dropTable('reach_content_email_details', true);
        $this->forge->dropTable('reach_content_social_details', true);
        $this->forge->dropTable('reach_content_video_details', true);
        $this->forge->dropTable('reach_content_community_details', true);
        $this->forge->dropTable('reach_content_knowledge_base_details', true);
        $this->forge->dropTable('reach_content_blog_details', true);
    }
}
