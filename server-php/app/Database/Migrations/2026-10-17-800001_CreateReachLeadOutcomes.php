<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Engage → Reach lead outcomes (docs/integrations/ENGAGE_OUTCOMES.md).
 *
 * - reach_lead_outcomes: one row per Engage outcome event, unique on the
 *   Engage `event_id` (the idempotency key). Each row records how it was
 *   matched to a Reach lead and the campaign / content / source it was
 *   attributed to at receipt time.
 * - reach_leads gains the seller referral code (`ref`) and UTM / landing data
 *   captured by the public lead form (sent on to Engage in reach_meta), plus
 *   the latest Engage stage and sales status.
 *
 * Raw DDL with IF [NOT] EXISTS so re-runs are harmless.
 */
class CreateReachLeadOutcomes extends Migration
{
    private const LEAD_COLUMNS = [
        'ref_code'            => 'VARCHAR(32) NULL',
        'utm_source'          => 'VARCHAR(120) NULL',
        'utm_medium'          => 'VARCHAR(120) NULL',
        'utm_campaign'        => 'VARCHAR(240) NULL',
        'utm_content'         => 'VARCHAR(240) NULL',
        'utm_term'            => 'VARCHAR(240) NULL',
        'landing_url'         => 'TEXT NULL',
        'referrer_url'        => 'TEXT NULL',
        'engage_stage'        => 'VARCHAR(32) NULL',
        'engage_sales_status' => 'VARCHAR(64) NULL',
        'engage_stage_at'     => 'TIMESTAMPTZ NULL',
    ];

    public function up(): void
    {
        foreach (self::LEAD_COLUMNS as $column => $type) {
            $this->db->query("ALTER TABLE reach_leads ADD COLUMN IF NOT EXISTS {$column} {$type}");
        }
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_leads_engage_lead_code ON reach_leads (engage_lead_code) WHERE engage_lead_code IS NOT NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_leads_ref_code ON reach_leads (ref_code) WHERE ref_code IS NOT NULL');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_lead_outcomes (
                id                   BIGSERIAL PRIMARY KEY,
                event_id             VARCHAR(100) NOT NULL,
                lead_id              BIGINT NULL REFERENCES reach_leads(id) ON DELETE SET NULL,
                engage_lead_code     VARCHAR(100) NULL,
                claimed_reach_lead_id BIGINT NULL,
                email_sha256         CHAR(64) NULL,
                match_method         VARCHAR(24) NOT NULL DEFAULT 'unmatched',
                stage                VARCHAR(32) NOT NULL,
                sales_status         VARCHAR(64) NULL,
                product              VARCHAR(100) NULL,
                value_inr            NUMERIC(14,2) NULL,
                occurred_at          TIMESTAMPTZ NOT NULL,
                seller_code          VARCHAR(32) NULL,
                campaign_code        VARCHAR(100) NULL,
                source_portal        VARCHAR(100) NULL,
                campaign_id          BIGINT NULL REFERENCES reach_campaigns(id) ON DELETE SET NULL,
                landing_page_id      BIGINT NULL REFERENCES reach_landing_pages(id) ON DELETE SET NULL,
                content_identity_id  BIGINT NULL REFERENCES reach_content_identities(id) ON DELETE SET NULL,
                source               VARCHAR(120) NULL,
                attribution_method   VARCHAR(24) NOT NULL DEFAULT 'unattributed',
                raw_event            JSONB NULL,
                received_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_reach_lead_outcomes_event UNIQUE (event_id),
                CONSTRAINT chk_reach_lead_outcomes_match CHECK (match_method IN ('reach_lead_id','engage_lead_code','email_sha256','unmatched')),
                CONSTRAINT chk_reach_lead_outcomes_attr  CHECK (attribution_method IN ('lead_campaign','touchpoint','campaign_code','lead_source','unattributed')),
                CONSTRAINT chk_reach_lead_outcomes_value CHECK (value_inr IS NULL OR value_inr >= 0)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_lead_outcomes_occurred ON reach_lead_outcomes (occurred_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_lead_outcomes_lead ON reach_lead_outcomes (lead_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_lead_outcomes_campaign ON reach_lead_outcomes (campaign_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_lead_outcomes_code ON reach_lead_outcomes (engage_lead_code)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_lead_outcomes');
        $this->db->query('DROP INDEX IF EXISTS idx_reach_leads_ref_code');
        $this->db->query('DROP INDEX IF EXISTS idx_reach_leads_engage_lead_code');
        foreach (array_reverse(array_keys(self::LEAD_COLUMNS)) as $column) {
            $this->db->query("ALTER TABLE reach_leads DROP COLUMN IF EXISTS {$column}");
        }
    }
}
