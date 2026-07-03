<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachSocialPosts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGSERIAL'],
            'campaign_id'      => ['type' => 'BIGINT', 'null' => true],
            'channel'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'content'          => ['type' => 'TEXT', 'null' => false, 'default' => ''],
            'media_refs'       => ['type' => 'JSONB', 'null' => true],
            'hashtags'         => ['type' => 'JSONB', 'null' => true],
            'scheduled_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'published_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'external_post_id' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'error_message'    => ['type' => 'TEXT', 'null' => true],
            'approval_status'  => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'not_required'],
            'approved_by'      => ['type' => 'BIGINT', 'null' => true],
            'approved_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'bot_generated'    => ['type' => 'BOOLEAN', 'default' => false],
            'created_by'       => ['type' => 'BIGINT', 'null' => true],
            'created_at'       => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'       => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('channel');
        $this->forge->addKey('status');
        $this->forge->addKey('approval_status');
        $this->forge->addKey('scheduled_at');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'SET NULL');
        $this->forge->createTable('reach_social_posts', true);

        $this->db->query(
            "ALTER TABLE reach_social_posts DROP CONSTRAINT IF EXISTS reach_social_posts_channel_check"
        );
        $this->db->query(
            "ALTER TABLE reach_social_posts ADD CONSTRAINT reach_social_posts_channel_check "
            . "CHECK (channel IN ('linkedin','twitter','facebook','instagram','youtube','whatsapp_channel','email_newsletter'))"
        );
        $this->db->query(
            "ALTER TABLE reach_social_posts DROP CONSTRAINT IF EXISTS reach_social_posts_status_check"
        );
        $this->db->query(
            "ALTER TABLE reach_social_posts ADD CONSTRAINT reach_social_posts_status_check "
            . "CHECK (status IN ('draft','pending_approval','approved','scheduled','posted','failed','manual_queue','archived'))"
        );
        $this->db->query(
            "ALTER TABLE reach_social_posts DROP CONSTRAINT IF EXISTS reach_social_posts_approval_check"
        );
        $this->db->query(
            "ALTER TABLE reach_social_posts ADD CONSTRAINT reach_social_posts_approval_check "
            . "CHECK (approval_status IN ('not_required','pending','approved','rejected'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_social_posts', true);
    }
}
