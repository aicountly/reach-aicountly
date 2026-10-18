<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * social-api.ai publishing (posts and YouTube videos).
 *
 * reach_social_posts
 *   - status `publishing`: social-api.ai accepted the post (HTTP 201) and is
 *     delivering it in the background. A post becomes `posted` only when
 *     GET /v1/posts/{id} reports its target published (SocialPostStatusPoller).
 *   - title (YouTube video title), the social-api.ai account the post went to,
 *     the provider's last per-target status / error code, the redacted raw
 *     response (for diagnosis), and poll bookkeeping.
 *
 * reach_video_pipeline_items
 *   - youtube_publisher: `social_api` or `direct`, fixed per item when its
 *     YouTube step starts (a settings change never mixes paths mid-flight).
 *   - youtube_social_post_id: the reach_social_posts row that carries the
 *     video to YouTube through social-api.ai.
 */
class SocialApiPublishing extends Migration
{
    private const STATUSES_OLD = "'draft','pending_approval','approved','scheduled','posted','failed','manual_queue','archived'";

    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_social_posts DROP CONSTRAINT IF EXISTS reach_social_posts_status_check');
        $this->db->query('ALTER TABLE reach_social_posts ADD CONSTRAINT reach_social_posts_status_check CHECK (status IN (' . self::STATUSES_OLD . ",'publishing'))");

        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS title VARCHAR(255) NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS platform_account_id VARCHAR(200) NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS provider_status VARCHAR(40) NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS provider_error_code VARCHAR(120) NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS provider_response_json JSONB NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMPTZ NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS last_polled_at TIMESTAMPTZ NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS poll_count INTEGER NOT NULL DEFAULT 0');
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_social_posts_publishing ON reach_social_posts (last_polled_at) WHERE status = 'publishing'");

        $this->db->query('ALTER TABLE reach_video_pipeline_items ADD COLUMN IF NOT EXISTS youtube_publisher VARCHAR(16) NULL');
        $this->db->query('ALTER TABLE reach_video_pipeline_items ADD COLUMN IF NOT EXISTS youtube_social_post_id BIGINT NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_video_pipeline_items DROP COLUMN IF EXISTS youtube_social_post_id');
        $this->db->query('ALTER TABLE reach_video_pipeline_items DROP COLUMN IF EXISTS youtube_publisher');

        $this->db->query('DROP INDEX IF EXISTS idx_social_posts_publishing');
        foreach (['poll_count', 'last_polled_at', 'submitted_at', 'provider_response_json', 'provider_error_code', 'provider_status', 'platform_account_id', 'title'] as $col) {
            $this->db->query("ALTER TABLE reach_social_posts DROP COLUMN IF EXISTS {$col}");
        }

        // Rows still in flight cannot be represented by the old constraint; they
        // are recorded as failed (never posted) with the reason.
        $this->db->query("UPDATE reach_social_posts SET status = 'failed', error_message = COALESCE(error_message, 'Rolled back while social-api.ai was still publishing; outcome unknown — check social-api.ai.') WHERE status = 'publishing'");
        $this->db->query('ALTER TABLE reach_social_posts DROP CONSTRAINT IF EXISTS reach_social_posts_status_check');
        $this->db->query('ALTER TABLE reach_social_posts ADD CONSTRAINT reach_social_posts_status_check CHECK (status IN (' . self::STATUSES_OLD . '))');
    }
}
