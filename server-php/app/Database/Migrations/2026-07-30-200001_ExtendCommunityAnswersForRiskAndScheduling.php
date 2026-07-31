<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Additive corrective migration.
 *
 * Phase 5 shipped official answers with a four-value risk_classification but no
 * numeric risk tier, no schedule slot and no freshness tracking. Those are
 * required by the bot-first publication gates, so they are added here rather
 * than by editing the already-deployed 100094 migration.
 */
class ExtendCommunityAnswersForRiskAndScheduling extends Migration
{
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE reach_community_official_answers
                ADD COLUMN IF NOT EXISTS risk_tier              SMALLINT NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS scheduled_at           TIMESTAMPTZ,
                ADD COLUMN IF NOT EXISTS verification_provider  VARCHAR(80),
                ADD COLUMN IF NOT EXISTS verified_at            TIMESTAMPTZ,
                ADD COLUMN IF NOT EXISTS applicability_date     DATE,
                ADD COLUMN IF NOT EXISTS freshness_deadline     DATE,
                ADD COLUMN IF NOT EXISTS reverification_state   VARCHAR(24) NOT NULL DEFAULT 'not_required',
                ADD COLUMN IF NOT EXISTS style_profile_id       BIGINT,
                ADD COLUMN IF NOT EXISTS operational_role       VARCHAR(40),
                ADD COLUMN IF NOT EXISTS revision_number        INTEGER NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS public_correction_note TEXT
        ");

        $this->db->query('ALTER TABLE reach_community_official_answers DROP CONSTRAINT IF EXISTS chk_rcoa_risk_tier');
        $this->db->query('
            ALTER TABLE reach_community_official_answers
                ADD CONSTRAINT chk_rcoa_risk_tier CHECK (risk_tier BETWEEN 0 AND 4)
        ');

        $this->db->query('ALTER TABLE reach_community_official_answers DROP CONSTRAINT IF EXISTS chk_rcoa_reverification_state');
        $this->db->query("
            ALTER TABLE reach_community_official_answers
                ADD CONSTRAINT chk_rcoa_reverification_state
                CHECK (reverification_state IN ('not_required','scheduled','due','in_progress','failed','complete'))
        ");

        // Backfill risk_tier from the existing classification so old rows gate correctly.
        $this->db->query("
            UPDATE reach_community_official_answers
               SET risk_tier = CASE risk_classification
                   WHEN 'low'      THEN 0
                   WHEN 'medium'   THEN 1
                   WHEN 'high'     THEN 2
                   WHEN 'critical' THEN 3
                   ELSE 1
               END
        ");

        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_risk_tier ON reach_community_official_answers(risk_tier)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_scheduled ON reach_community_official_answers(scheduled_at)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_freshness ON reach_community_official_answers(freshness_deadline)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoa_pub_ext ON reach_community_official_answers(public_external_id)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_community_official_answers DROP CONSTRAINT IF EXISTS chk_rcoa_risk_tier');
        $this->db->query('ALTER TABLE reach_community_official_answers DROP CONSTRAINT IF EXISTS chk_rcoa_reverification_state');
        $this->db->query("
            ALTER TABLE reach_community_official_answers
                DROP COLUMN IF EXISTS risk_tier,
                DROP COLUMN IF EXISTS scheduled_at,
                DROP COLUMN IF EXISTS verification_provider,
                DROP COLUMN IF EXISTS verified_at,
                DROP COLUMN IF EXISTS applicability_date,
                DROP COLUMN IF EXISTS freshness_deadline,
                DROP COLUMN IF EXISTS reverification_state,
                DROP COLUMN IF EXISTS style_profile_id,
                DROP COLUMN IF EXISTS operational_role,
                DROP COLUMN IF EXISTS revision_number,
                DROP COLUMN IF EXISTS public_correction_note
        ");
    }
}
