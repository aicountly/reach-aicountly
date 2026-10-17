<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Release notes and workflow guides submitted by the Claude Routines
 * (stream D, 2026-10-17).
 *
 * Each POST to v1/automation/release-notes or v1/automation/workflow-guides
 * is one row here: what the routine sent, the vetting verdict that decided
 * it, and how far it got (pending_vetting → approved → published, or
 * rejected / failed / superseded). An approved submission is published
 * through the ordinary publication pipeline, so it also gets a
 * reach_content_items row (content_type release_note | workflow_guide —
 * one per public page) and a reach_content_versions row holding the exact
 * payload sent to aicountly.com. That is why the content-type CHECK on
 * reach_content_items is widened here.
 */
class CreateReachStructuredContentSubmissions extends Migration
{
    private const BASE_TYPES = "'blog','knowledge_base','community_question','community_answer',"
        . "'video_topic','video_script','social_post','email','whatsapp','sms',"
        . "'landing_page','product_announcement','release_announcement',"
        . "'webinar','case_study','content_refresh'";

    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_content_type_chk');
        $this->db->query(
            'ALTER TABLE reach_content_items ADD CONSTRAINT rci_content_type_chk CHECK (content_type IN ('
            . self::BASE_TYPES . ",'release_note','workflow_guide'))"
        );

        $this->db->query("CREATE TABLE IF NOT EXISTS reach_structured_content_submissions (
            id                  BIGSERIAL PRIMARY KEY,
            content_type        VARCHAR(32)  NOT NULL CHECK (content_type IN ('release_note','workflow_guide')),
            external_key        VARCHAR(191) NOT NULL,
            product_slug        VARCHAR(64)  NOT NULL,
            public_slug         VARCHAR(120) NOT NULL,
            version             VARCHAR(32)  NULL,
            version_sort        BIGINT       NULL,
            title               VARCHAR(200) NOT NULL,
            payload_json        JSONB        NOT NULL,
            payload_sha256      CHAR(64)     NOT NULL,
            final_json          JSONB        NULL,
            source_json         JSONB        NULL,
            source_repo         VARCHAR(120) NULL,
            source_to_ref       VARCHAR(120) NULL,
            status              VARCHAR(24)  NOT NULL DEFAULT 'pending_vetting'
                                CHECK (status IN ('pending_vetting','rejected','approved','published','failed','superseded')),
            vetting_decision    VARCHAR(16)  NULL,
            vetting_run_id      BIGINT       NULL REFERENCES reach_ai_vetting_runs(id) ON DELETE SET NULL,
            vetting_run_ids     JSONB        NOT NULL DEFAULT '[]'::jsonb,
            reasons_json        JSONB        NOT NULL DEFAULT '[]'::jsonb,
            approved_by_user_id BIGINT       NULL REFERENCES reach_users(id) ON DELETE SET NULL,
            approved_at         TIMESTAMPTZ  NULL,
            content_item_id     BIGINT       NULL REFERENCES reach_content_items(id) ON DELETE SET NULL,
            content_version_id  BIGINT       NULL REFERENCES reach_content_versions(id) ON DELETE SET NULL,
            deployment_id       BIGINT       NULL REFERENCES reach_publication_deployments(id) ON DELETE SET NULL,
            public_content_id   BIGINT       NULL,
            public_url          VARCHAR(2048) NULL,
            published_at        TIMESTAMPTZ  NULL,
            attempt_count       INTEGER      NOT NULL DEFAULT 0,
            error_category      VARCHAR(64)  NULL,
            last_error          TEXT         NULL,
            next_attempt_at     TIMESTAMPTZ  NULL,
            superseded_by_id    BIGINT       NULL,
            created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT rscs_external_key_uq UNIQUE (content_type, external_key),
            CONSTRAINT rscs_release_version_chk CHECK (content_type <> 'release_note' OR (version IS NOT NULL AND version_sort IS NOT NULL))
        )");

        // One live note per product version; one in-flight submission per guide.
        $this->db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_rscs_release_version
            ON reach_structured_content_submissions (product_slug, version_sort)
            WHERE content_type = 'release_note' AND status IN ('pending_vetting','approved','published')");
        $this->db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_rscs_guide_in_flight
            ON reach_structured_content_submissions (product_slug, public_slug)
            WHERE content_type = 'workflow_guide' AND status IN ('pending_vetting','approved')");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rscs_type_product ON reach_structured_content_submissions (content_type, product_slug, status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rscs_sweep ON reach_structured_content_submissions (status, next_attempt_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_structured_content_submissions');
        $this->db->query('ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_content_type_chk');
        // NOT VALID: rows of the removed types may still exist (their deployments are RESTRICT).
        $this->db->query(
            'ALTER TABLE reach_content_items ADD CONSTRAINT rci_content_type_chk CHECK (content_type IN ('
            . self::BASE_TYPES . ')) NOT VALID'
        );
    }
}
