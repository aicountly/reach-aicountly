<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stream V — the automated video pipeline (routine script → Gemini vetting →
 * OpenAI voice-over → Shotstack render → YouTube upload).
 *
 *   reach_video_pipeline_items   one row per routine submission (vlog or Short):
 *                                the vetted script, where the pipeline is, and
 *                                the real render / YouTube ids once they exist.
 *   reach_video_pipeline_assets  per-scene voice-overs and visuals plus the
 *                                thumbnail, so each step is resumable.
 *   reach_video_media            binaries Reach stores for the renderer (MP3
 *                                voice-overs, JPEG/PNG stills), served through
 *                                expiring signed URLs with Range support.
 *   reach_video_youtube_channel  the one connected YouTube channel (the refresh
 *                                token itself is an encrypted ReachConfig secret).
 *   reach_video_oauth_states     single-use OAuth `state` nonces.
 */
class CreateReachVideoPipeline extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS reach_video_pipeline_items (
            id                    BIGSERIAL    PRIMARY KEY,
            uuid                  UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
            external_key          VARCHAR(191) NOT NULL UNIQUE,
            format                VARCHAR(8)   NOT NULL CHECK (format IN ('vlog','short')),
            title                 VARCHAR(100) NOT NULL,
            product_slug          VARCHAR(80)  NULL,
            submitted_json        JSONB        NOT NULL,
            script_json           JSONB        NOT NULL,
            status                VARCHAR(24)  NOT NULL DEFAULT 'pending_vetting'
                                  CHECK (status IN ('pending_vetting','rejected','in_progress','blocked','failed','published','cancelled')),
            step                  VARCHAR(24)  NOT NULL DEFAULT 'vet'
                                  CHECK (step IN ('vet','tts','visuals','render_submit','render_poll','youtube_upload','youtube_extras','social','done')),
            step_detail           TEXT         NULL,
            last_error            TEXT         NULL,
            warnings_json         JSONB        NOT NULL DEFAULT '[]'::jsonb,
            vetting_decision      VARCHAR(16)  NULL,
            vetting_model         VARCHAR(120) NULL,
            vetting_reasons_json  JSONB        NOT NULL DEFAULT '[]'::jsonb,
            vetting_run_ids_json  JSONB        NOT NULL DEFAULT '[]'::jsonb,
            approved_by_user_id   BIGINT       NULL,
            approval_id           BIGINT       NULL,
            approved_at           TIMESTAMPTZ  NULL,
            duration_secs         NUMERIC(8,2) NULL,
            render_id             VARCHAR(80)  NULL,
            render_status         VARCHAR(24)  NULL,
            render_url            TEXT         NULL,
            render_poster_url     TEXT         NULL,
            render_thumbnail_url  TEXT         NULL,
            render_plan_json      JSONB        NOT NULL DEFAULT '{}'::jsonb,
            render_polls          INTEGER      NOT NULL DEFAULT 0,
            render_attempts       INTEGER      NOT NULL DEFAULT 0,
            render_submitted_at   TIMESTAMPTZ  NULL,
            rendered_at           TIMESTAMPTZ  NULL,
            youtube_upload_url    TEXT         NULL,
            youtube_upload_bytes  BIGINT       NULL,
            youtube_upload_offset BIGINT       NOT NULL DEFAULT 0,
            youtube_video_id      VARCHAR(32)  NULL,
            youtube_url           TEXT         NULL,
            youtube_privacy       VARCHAR(16)  NULL,
            thumbnail_status      VARCHAR(16)  NULL,
            captions_status       VARCHAR(16)  NULL,
            social_post_ids_json  JSONB        NOT NULL DEFAULT '[]'::jsonb,
            published_at          TIMESTAMPTZ  NULL,
            created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvpi_format_created ON reach_video_pipeline_items (format, created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvpi_status ON reach_video_pipeline_items (status, step)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvpi_render ON reach_video_pipeline_items (render_id)');

        $this->db->query("CREATE TABLE IF NOT EXISTS reach_video_media (
            id               BIGSERIAL    PRIMARY KEY,
            uuid             UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
            kind             VARCHAR(16)  NOT NULL CHECK (kind IN ('audio','image')),
            mime             VARCHAR(64)  NOT NULL,
            file_path        TEXT         NOT NULL,
            bytes            BIGINT       NOT NULL DEFAULT 0,
            checksum_sha256  CHAR(64)     NOT NULL,
            width            INTEGER      NULL,
            height           INTEGER      NULL,
            duration_ms      INTEGER      NULL,
            item_id          BIGINT       NULL REFERENCES reach_video_pipeline_items(id) ON DELETE SET NULL,
            created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvm_item ON reach_video_media (item_id)');

        $this->db->query("CREATE TABLE IF NOT EXISTS reach_video_pipeline_assets (
            id           BIGSERIAL    PRIMARY KEY,
            item_id      BIGINT       NOT NULL REFERENCES reach_video_pipeline_items(id) ON DELETE CASCADE,
            kind         VARCHAR(16)  NOT NULL CHECK (kind IN ('voiceover','visual','thumbnail')),
            scene_order  INTEGER      NOT NULL DEFAULT 0,
            source       VARCHAR(16)  NOT NULL DEFAULT 'none'
                         CHECK (source IN ('tts','screenshot','dalle','title_card','none')),
            status       VARCHAR(16)  NOT NULL DEFAULT 'pending'
                         CHECK (status IN ('pending','ready','failed','skipped')),
            media_id     BIGINT       NULL REFERENCES reach_video_media(id) ON DELETE SET NULL,
            duration_ms  INTEGER      NULL,
            attempts     INTEGER      NOT NULL DEFAULT 0,
            detail       TEXT         NULL,
            created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            UNIQUE (item_id, kind, scene_order)
        )");

        $this->db->query("CREATE TABLE IF NOT EXISTS reach_video_youtube_channel (
            id                   SMALLINT     PRIMARY KEY DEFAULT 1 CHECK (id = 1),
            status               VARCHAR(16)  NOT NULL DEFAULT 'disconnected'
                                 CHECK (status IN ('connected','disconnected','error')),
            channel_id           VARCHAR(64)  NULL,
            channel_title        VARCHAR(255) NULL,
            scopes               TEXT         NULL,
            connected_by_user_id BIGINT       NULL,
            connected_at         TIMESTAMPTZ  NULL,
            last_error           TEXT         NULL,
            updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");

        $this->db->query("CREATE TABLE IF NOT EXISTS reach_video_oauth_states (
            nonce       VARCHAR(64)  PRIMARY KEY,
            user_id     BIGINT       NULL,
            created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            expires_at  TIMESTAMPTZ  NOT NULL,
            used_at     TIMESTAMPTZ  NULL
        )");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_oauth_states');
        $this->db->query('DROP TABLE IF EXISTS reach_video_youtube_channel');
        $this->db->query('DROP TABLE IF EXISTS reach_video_pipeline_assets');
        $this->db->query('DROP TABLE IF EXISTS reach_video_media');
        $this->db->query('DROP TABLE IF EXISTS reach_video_pipeline_items');
    }
}
