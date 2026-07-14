<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPhase7FieldsToSocialPosts extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS uuid UUID DEFAULT gen_random_uuid() UNIQUE');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS tenant_id BIGINT');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS connection_id BIGINT');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS destination_id VARCHAR(255)');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS remote_post_id VARCHAR(255)');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS remote_url VARCHAR(500)');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS provider VARCHAR(64)');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS dispatch_id BIGINT');

        $this->db->query("UPDATE reach_social_posts SET uuid = gen_random_uuid() WHERE uuid IS NULL");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_social_posts_uuid ON reach_social_posts(uuid)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_social_posts_tenant ON reach_social_posts(tenant_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_social_posts_dispatch ON reach_social_posts(dispatch_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_social_posts_dispatch');
        $this->db->query('DROP INDEX IF EXISTS idx_social_posts_tenant');
        $this->db->query('DROP INDEX IF EXISTS idx_social_posts_uuid');
        foreach (['dispatch_id','provider','remote_url','remote_post_id','destination_id','connection_id','tenant_id','uuid'] as $col) {
            $this->db->query("ALTER TABLE reach_social_posts DROP COLUMN IF EXISTS {$col}");
        }
    }
}
