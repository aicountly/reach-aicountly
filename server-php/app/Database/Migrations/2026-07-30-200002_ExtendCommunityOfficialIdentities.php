<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Additive corrective migration.
 *
 * Public bot identities must be stable and disclosed. The Phase 5 identity
 * table lacked the public-facing fields: the slug shown on aicountly.com, the
 * topic area, the operational role, the AI disclosure string, the public
 * external ID and approval provenance.
 */
class ExtendCommunityOfficialIdentities extends Migration
{
    private const DISCLOSURE = 'AI-assisted AICOUNTLY community contributor';

    public function up(): void
    {
        $this->db->query("
            ALTER TABLE reach_community_official_identities
                ADD COLUMN IF NOT EXISTS public_slug          VARCHAR(120),
                ADD COLUMN IF NOT EXISTS short_description    TEXT,
                ADD COLUMN IF NOT EXISTS topic_specialisation VARCHAR(200),
                ADD COLUMN IF NOT EXISTS operational_role     VARCHAR(40) NOT NULL DEFAULT 'expert_answer_assistant',
                ADD COLUMN IF NOT EXISTS ai_disclosure        TEXT NOT NULL DEFAULT '" . self::DISCLOSURE . "',
                ADD COLUMN IF NOT EXISTS public_external_id   VARCHAR(120),
                ADD COLUMN IF NOT EXISTS created_by           BIGINT,
                ADD COLUMN IF NOT EXISTS approved_by          BIGINT
        ");

        $this->db->query('ALTER TABLE reach_community_official_identities DROP CONSTRAINT IF EXISTS chk_rcoi_operational_role');
        $this->db->query("
            ALTER TABLE reach_community_official_identities
                ADD CONSTRAINT chk_rcoi_operational_role CHECK (operational_role IN (
                    'question_curator',
                    'expert_answer_assistant',
                    'community_steward',
                    'thread_facilitator',
                    'review_objection_desk'
                ))
        ");

        $this->db->query('UPDATE reach_community_official_identities SET public_slug = slug WHERE public_slug IS NULL');

        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS idx_rcoi_public_slug ON reach_community_official_identities(public_slug)');
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS idx_rcoi_public_ext ON reach_community_official_identities(public_external_id) WHERE public_external_id IS NOT NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoi_role ON reach_community_official_identities(operational_role)');

        $this->seedIdentities();
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_rcoi_public_slug');
        $this->db->query('DROP INDEX IF EXISTS idx_rcoi_public_ext');
        $this->db->query('DROP INDEX IF EXISTS idx_rcoi_role');
        $this->db->query('ALTER TABLE reach_community_official_identities DROP CONSTRAINT IF EXISTS chk_rcoi_operational_role');
        $this->db->query('
            ALTER TABLE reach_community_official_identities
                DROP COLUMN IF EXISTS public_slug,
                DROP COLUMN IF EXISTS short_description,
                DROP COLUMN IF EXISTS topic_specialisation,
                DROP COLUMN IF EXISTS operational_role,
                DROP COLUMN IF EXISTS ai_disclosure,
                DROP COLUMN IF EXISTS public_external_id,
                DROP COLUMN IF EXISTS created_by,
                DROP COLUMN IF EXISTS approved_by
        ');
    }

    /**
     * Stable public bot identities. Names are explicitly non-human: no invented
     * qualifications, photographs, locations or professional histories, and the
     * AI disclosure is mandatory on every one.
     */
    private function seedIdentities(): void
    {
        $identities = [
            ['aicountly-accounting-guide',  'AICOUNTLY Accounting Guide',   'Accounting fundamentals and bookkeeping',      'expert_answer_assistant', 'Accounting'],
            ['aicountly-gst-guide',         'AICOUNTLY GST Guide',          'GST registration, returns and compliance',     'expert_answer_assistant', 'Compliance'],
            ['aicountly-income-tax-desk',   'AICOUNTLY Income Tax Desk',    'Income tax filing, TDS and assessments',       'expert_answer_assistant', 'Compliance'],
            ['aicountly-smart-books-guide', 'AICOUNTLY Smart Books Guide',  'AICOUNTLY Smart Books product usage',          'expert_answer_assistant', 'Product'],
            ['aicountly-payroll-desk',      'AICOUNTLY Payroll Desk',       'Payroll, PF, ESIC and salary processing',      'expert_answer_assistant', 'Compliance'],
            ['aicountly-compliance-desk',   'AICOUNTLY Compliance Desk',    'Statutory compliance calendars and filings',   'expert_answer_assistant', 'Compliance'],
            ['aicountly-review-desk',       'AICOUNTLY Review Desk',        'Verification, objections and corrections',     'review_objection_desk',   'Compliance'],
            ['aicountly-question-curator',  'AICOUNTLY Question Curator',   'Sourcing and curating community questions',    'question_curator',        'General'],
            ['aicountly-community-steward', 'AICOUNTLY Community Steward',  'Tagging, categorisation and related questions','community_steward',       'General'],
            ['aicountly-thread-facilitator','AICOUNTLY Thread Facilitator', 'Clarifications, exceptions and comparisons',   'thread_facilitator',      'General'],
        ];

        foreach ($identities as [$slug, $displayName, $description, $role, $department]) {
            $this->db->query(
                "INSERT INTO reach_community_official_identities
                    (uuid, slug, public_slug, display_name, short_description, topic_specialisation,
                     operational_role, department, badge_type, disclosure_template, ai_disclosure,
                     authorised_scopes, approval_requirements, is_active, created_at, updated_at)
                 VALUES
                    (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, 'official', ?, ?,
                     '{}'::text[], '{}'::jsonb, TRUE, NOW(), NOW())
                 ON CONFLICT (slug) DO UPDATE SET
                    public_slug          = EXCLUDED.public_slug,
                    short_description    = EXCLUDED.short_description,
                    topic_specialisation = EXCLUDED.topic_specialisation,
                    operational_role     = EXCLUDED.operational_role,
                    ai_disclosure        = EXCLUDED.ai_disclosure,
                    updated_at           = NOW()",
                [$slug, $slug, $displayName, $description, $description, $role, $department, self::DISCLOSURE, self::DISCLOSURE]
            );
        }

        // Existing Phase 5 identities predate the disclosure requirement.
        $this->db->query(
            "UPDATE reach_community_official_identities
                SET ai_disclosure = ?
              WHERE ai_disclosure IS NULL OR ai_disclosure = ''",
            [self::DISCLOSURE]
        );
    }
}
