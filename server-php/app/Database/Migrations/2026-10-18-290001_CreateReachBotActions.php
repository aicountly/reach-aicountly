<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bot actions: "Run now" for every bot activity, and owner commands to the
 * reach-command Claude Routine (docs/operations/BOT_ACTIONS.md).
 *
 * reach_bot_commands — one row per owner command (Bot → Ask the bot) and per
 *   "Run now" of a Claude routine. The row is written (status `firing`)
 *   BEFORE the routine is fired; its unique idempotency_key is what stops a
 *   double click or a retried request from firing twice (the Routines API has
 *   no idempotency key: every fire is a new session).
 *     status: firing → submitted (HTTP 200, session URL stored)
 *                    → failed (refused / not sent; fire_error says why)
 *                    → outcome_unknown (sent, no answer: check claude.ai)
 *             submitted → in_progress | completed | partial | failed
 *             (POST v1/automation/commands/{id}/report from the routine)
 *
 * reach_bot_activity_runs — one row per manual "Run now" of a job / command
 *   activity, keyed by the client's idempotency key, pointing at the job it
 *   queued (its live status is the job's).
 *
 * reach_automation_social_submissions — social posts submitted through
 *   v1/automation/social-posts: Gemini decision, approval, the
 *   reach_social_posts rows created.
 *
 * reach_video_pipeline_items.bot_command_id / social_channels_json — a video
 *   made for an owner command (outside the routine's daily quota) and the
 *   social networks its Short is offered to (null = the default set).
 * reach_social_posts.bot_command_id — which command a post was made for.
 */
class CreateReachBotActions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_bot_commands (
                id                BIGSERIAL PRIMARY KEY,
                uuid              UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                kind              VARCHAR(16) NOT NULL DEFAULT 'command' CHECK (kind IN ('command','activity_run')),
                activity_key      VARCHAR(80),
                instruction       TEXT NOT NULL,
                channels_json     JSONB NOT NULL DEFAULT '[]'::jsonb,
                attachments_json  JSONB NOT NULL DEFAULT '[]'::jsonb,
                payload_text      TEXT,
                payload_chars     INT,
                idempotency_key   VARCHAR(120) NOT NULL UNIQUE,
                status            VARCHAR(20) NOT NULL DEFAULT 'firing'
                                  CHECK (status IN ('firing','submitted','failed','outcome_unknown','in_progress','completed','partial')),
                fire_http_status  INT,
                fire_error        TEXT,
                retryable         BOOLEAN NOT NULL DEFAULT false,
                retry_after_secs  INT,
                session_id        VARCHAR(160),
                session_url       VARCHAR(500),
                report_status     VARCHAR(20) CHECK (report_status IS NULL OR report_status IN ('in_progress','completed','partial','failed')),
                report_summary    TEXT,
                report_links_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                report_count      INT NOT NULL DEFAULT 0,
                reported_at       TIMESTAMPTZ,
                created_by        BIGINT,
                created_by_name   VARCHAR(200),
                created_by_email  VARCHAR(255),
                fired_at          TIMESTAMPTZ,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_bot_commands_created ON reach_bot_commands (created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_bot_commands_activity ON reach_bot_commands (activity_key, created_at DESC) WHERE activity_key IS NOT NULL');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_bot_activity_runs (
                id                 BIGSERIAL PRIMARY KEY,
                uuid               UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                activity_key       VARCHAR(80) NOT NULL,
                kind               VARCHAR(16) NOT NULL CHECK (kind IN ('job','command','routine')),
                idempotency_key    VARCHAR(120) NOT NULL UNIQUE,
                status             VARCHAR(16) NOT NULL CHECK (status IN ('queued','fired','failed')),
                job_id             BIGINT,
                bot_command_id     BIGINT,
                message            TEXT,
                requested_by       BIGINT,
                requested_by_name  VARCHAR(200),
                requested_by_email VARCHAR(255),
                created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_bot_activity_runs_key ON reach_bot_activity_runs (activity_key, created_at DESC)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_automation_social_submissions (
                id                   BIGSERIAL PRIMARY KEY,
                uuid                 UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                external_key         VARCHAR(160) NOT NULL UNIQUE,
                bot_command_id       BIGINT,
                channels_json        JSONB NOT NULL DEFAULT '[]'::jsonb,
                submitted_json       JSONB NOT NULL DEFAULT '{}'::jsonb,
                final_json           JSONB,
                status               VARCHAR(20) NOT NULL DEFAULT 'pending_vetting'
                                     CHECK (status IN ('pending_vetting','approved','rejected')),
                vetting_decision     VARCHAR(16),
                vetting_model        VARCHAR(120),
                vetting_reasons_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                vetting_run_ids_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                social_post_ids_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                last_error           TEXT,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_auto_social_created ON reach_automation_social_submissions (created_at DESC)');

        $this->db->query('ALTER TABLE reach_video_pipeline_items ADD COLUMN IF NOT EXISTS bot_command_id BIGINT NULL');
        $this->db->query('ALTER TABLE reach_video_pipeline_items ADD COLUMN IF NOT EXISTS social_channels_json JSONB NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_video_items_bot_command ON reach_video_pipeline_items (bot_command_id) WHERE bot_command_id IS NOT NULL');
        $this->db->query('ALTER TABLE reach_social_posts ADD COLUMN IF NOT EXISTS bot_command_id BIGINT NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_social_posts_bot_command ON reach_social_posts (bot_command_id) WHERE bot_command_id IS NOT NULL');

        // bot.run / bot.command for the roles that already hold the bot group
        // (RolesAndPermissionsSeeder gives reach_admin every bot.* slug;
        // super_admin holds `*`). Existing role rows are not re-seeded on deploy.
        foreach (['reach_admin'] as $slug) {
            $row = $this->db->query('SELECT id, permissions FROM reach_roles WHERE slug = ?', [$slug])->getRowArray();
            if ($row === null) {
                continue;
            }
            $perms = json_decode((string) $row['permissions'], true);
            if (! is_array($perms) || in_array('*', $perms, true) || in_array('bot.*', $perms, true)) {
                continue;
            }
            $merged = array_values(array_unique(array_merge($perms, ['bot.run', 'bot.command'])));
            $this->db->query('UPDATE reach_roles SET permissions = ?::jsonb, updated_at = NOW() WHERE id = ?', [json_encode($merged), (int) $row['id']]);
        }
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_social_posts_bot_command');
        $this->db->query('ALTER TABLE reach_social_posts DROP COLUMN IF EXISTS bot_command_id');
        $this->db->query('DROP INDEX IF EXISTS idx_video_items_bot_command');
        $this->db->query('ALTER TABLE reach_video_pipeline_items DROP COLUMN IF EXISTS social_channels_json');
        $this->db->query('ALTER TABLE reach_video_pipeline_items DROP COLUMN IF EXISTS bot_command_id');
        $this->db->query('DROP TABLE IF EXISTS reach_automation_social_submissions');
        $this->db->query('DROP TABLE IF EXISTS reach_bot_activity_runs');
        $this->db->query('DROP TABLE IF EXISTS reach_bot_commands');
        // Role permission additions are left in place (harmless without the routes).
    }
}
