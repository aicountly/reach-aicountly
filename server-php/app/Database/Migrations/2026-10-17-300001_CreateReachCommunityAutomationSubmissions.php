<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Community Q&A automation (stream B, 2026-10-17).
 *
 * 1. reach_community_automation_submissions — one row per routine submission
 *    (POST v1/automation/community-qa and community-answers): what was sent,
 *    the question/answer it became, the vetting decision and reasons, retry
 *    state while vetting is unavailable, and the public URL once live. It is
 *    the routine's idempotency key store and the Automated feed's source.
 * 2. reach_community_answer_approvals.vetting_run_id — an automatic approval
 *    names the vetting run it rests on, so the audit trail shows the approval
 *    came from the system account after a recorded vetting decision.
 * 3. The AICOUNTLY Product Guide desk — product questions about apps other
 *    than Smart Books (Billing, Inventory, Notes, Drive, …) were otherwise
 *    answered under the Smart Books or Compliance desk's name.
 */
class CreateReachCommunityAutomationSubmissions extends Migration
{
    private const DISCLOSURE = 'AI-assisted AICOUNTLY community contributor';

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_automation_submissions (
                id               BIGSERIAL PRIMARY KEY,
                external_key     VARCHAR(191) NOT NULL UNIQUE,
                kind             VARCHAR(20)  NOT NULL CHECK (kind IN ('faq','member_answer')),
                question_id      BIGINT REFERENCES reach_community_questions(id) ON DELETE SET NULL,
                answer_id        BIGINT REFERENCES reach_community_official_answers(id) ON DELETE SET NULL,
                status           VARCHAR(20)  NOT NULL DEFAULT 'pending'
                                     CHECK (status IN ('pending','approved','published','withdrawn','blocked','failed')),
                decision         VARCHAR(16)  NULL CHECK (decision IS NULL OR decision IN ('approve','revise','reject')),
                reasons_json     JSONB        NOT NULL DEFAULT '[]'::jsonb,
                vetting_run_ids  JSONB        NOT NULL DEFAULT '[]'::jsonb,
                revisions        SMALLINT     NOT NULL DEFAULT 0,
                attempts         INTEGER      NOT NULL DEFAULT 0,
                next_retry_at    TIMESTAMPTZ  NULL,
                last_error       TEXT         NULL,
                risk_tier        SMALLINT     NULL,
                category_slug    VARCHAR(120) NULL,
                product_slug     VARCHAR(120) NULL,
                question_title   VARCHAR(512) NOT NULL,
                public_url       TEXT         NULL,
                provenance_json  JSONB        NOT NULL DEFAULT '{}'::jsonb,
                published_at     TIMESTAMPTZ  NULL,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcas_status_retry ON reach_community_automation_submissions (status, next_retry_at)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcas_created ON reach_community_automation_submissions (created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcas_answer ON reach_community_automation_submissions (answer_id)');

        $this->db->query('ALTER TABLE reach_community_answer_approvals ADD COLUMN IF NOT EXISTS vetting_run_id BIGINT NULL');
        $this->db->query('ALTER TABLE reach_community_answer_approvals DROP CONSTRAINT IF EXISTS fk_rcaa_vetting_run');
        $this->db->query('
            ALTER TABLE reach_community_answer_approvals
                ADD CONSTRAINT fk_rcaa_vetting_run FOREIGN KEY (vetting_run_id)
                REFERENCES reach_ai_vetting_runs(id) ON DELETE SET NULL
        ');

        if ($this->db->tableExists('reach_community_audit_event_registry')) {
            foreach (['community.automation.submitted', 'community.automation.decided', 'community.automation.pending'] as $slug) {
                $this->db->query(
                    'INSERT INTO reach_community_audit_event_registry (event_slug) VALUES (?) ON CONFLICT (event_slug) DO NOTHING',
                    [$slug]
                );
            }
        }

        $this->db->query(
            "INSERT INTO reach_community_official_identities
                (uuid, slug, public_slug, display_name, short_description, topic_specialisation,
                 operational_role, department, badge_type, disclosure_template, ai_disclosure,
                 authorised_scopes, approval_requirements, is_active, created_at, updated_at)
             VALUES
                (gen_random_uuid(), 'aicountly-product-guide', 'aicountly-product-guide', 'AICOUNTLY Product Guide',
                 'Using AICOUNTLY apps: Billing, Inventory, Notes, Drive and more',
                 'Using AICOUNTLY apps: Billing, Inventory, Notes, Drive and more',
                 'expert_answer_assistant', 'Product', 'official', ?, ?,
                 '{}'::text[], '{}'::jsonb, TRUE, NOW(), NOW())
             ON CONFLICT (slug) DO NOTHING",
            [self::DISCLOSURE, self::DISCLOSURE]
        );
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_automation_submissions');
        $this->db->query('ALTER TABLE reach_community_answer_approvals DROP CONSTRAINT IF EXISTS fk_rcaa_vetting_run');
        $this->db->query('ALTER TABLE reach_community_answer_approvals DROP COLUMN IF EXISTS vetting_run_id');
        $this->db->table('reach_community_official_identities')
            ->where('slug', 'aicountly-product-guide')
            ->update(['is_active' => false, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
