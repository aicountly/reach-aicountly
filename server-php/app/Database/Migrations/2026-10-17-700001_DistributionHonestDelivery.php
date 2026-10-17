<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campaign readiness — honest delivery bookkeeping.
 *
 * - Audience recipients remember who they are (subject_type/subject_id →
 *   reach_leads.id) so a send can look up the real address at dispatch time;
 *   the snapshot itself still stores only a hash + masked address.
 * - Delivery attempts gain the `skipped` status (SMS_SKIP_SEND /
 *   WHATSAPP_SKIP_SEND — nothing left Reach) and the `not_configured` /
 *   `skipped` failure classes. dispatch_id becomes nullable: social posts and
 *   test sends are recorded without a campaign dispatch row.
 * - Dispatches record where their content comes from (source_type/source_id),
 *   how many sends were skipped and the last systemic error.
 *
 * Raw DDL with IF [NOT] EXISTS so re-runs are harmless.
 */
class DistributionHonestDelivery extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_campaign_audience_recipients ADD COLUMN IF NOT EXISTS subject_type VARCHAR(32) NULL');
        $this->db->query('ALTER TABLE reach_campaign_audience_recipients ADD COLUMN IF NOT EXISTS subject_id BIGINT NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_recipients_snapshot_eligibility ON reach_campaign_audience_recipients (snapshot_id, eligibility_status)');

        $this->db->query('ALTER TABLE reach_campaign_delivery_attempts ALTER COLUMN dispatch_id DROP NOT NULL');
        $this->db->query('ALTER TABLE reach_campaign_delivery_attempts DROP CONSTRAINT IF EXISTS chk_attempts_status');
        $this->db->query(
            "ALTER TABLE reach_campaign_delivery_attempts ADD CONSTRAINT chk_attempts_status "
            . "CHECK (status IN ('queued','sending','accepted','sent','delivered','read','failed','bounced','complained','unsubscribed','suppressed','skipped'))"
        );
        $this->db->query('ALTER TABLE reach_campaign_delivery_attempts DROP CONSTRAINT IF EXISTS chk_attempts_failure_class');
        $this->db->query(
            "ALTER TABLE reach_campaign_delivery_attempts ADD CONSTRAINT chk_attempts_failure_class "
            . "CHECK (failure_class IS NULL OR failure_class IN ('permanent','transient','rate_limit','rejected','unknown','not_configured','skipped'))"
        );
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_attempts_dispatch_recipient ON reach_campaign_delivery_attempts (dispatch_id, recipient_id)');

        $this->db->query('ALTER TABLE reach_campaign_dispatches ADD COLUMN IF NOT EXISTS source_type VARCHAR(32) NULL');
        $this->db->query('ALTER TABLE reach_campaign_dispatches ADD COLUMN IF NOT EXISTS source_id BIGINT NULL');
        $this->db->query('ALTER TABLE reach_campaign_dispatches ADD COLUMN IF NOT EXISTS skipped_count INT NOT NULL DEFAULT 0');
        $this->db->query('ALTER TABLE reach_campaign_dispatches ADD COLUMN IF NOT EXISTS last_error TEXT NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_campaign_dispatches DROP COLUMN IF EXISTS last_error');
        $this->db->query('ALTER TABLE reach_campaign_dispatches DROP COLUMN IF EXISTS skipped_count');
        $this->db->query('ALTER TABLE reach_campaign_dispatches DROP COLUMN IF EXISTS source_id');
        $this->db->query('ALTER TABLE reach_campaign_dispatches DROP COLUMN IF EXISTS source_type');

        $this->db->query('DROP INDEX IF EXISTS idx_attempts_dispatch_recipient');
        $this->db->query("UPDATE reach_campaign_delivery_attempts SET status = 'failed' WHERE status = 'skipped'");
        $this->db->query("UPDATE reach_campaign_delivery_attempts SET failure_class = 'unknown' WHERE failure_class IN ('not_configured','skipped')");
        $this->db->query('ALTER TABLE reach_campaign_delivery_attempts DROP CONSTRAINT IF EXISTS chk_attempts_failure_class');
        $this->db->query(
            "ALTER TABLE reach_campaign_delivery_attempts ADD CONSTRAINT chk_attempts_failure_class "
            . "CHECK (failure_class IS NULL OR failure_class IN ('permanent','transient','rate_limit','rejected','unknown'))"
        );
        $this->db->query('ALTER TABLE reach_campaign_delivery_attempts DROP CONSTRAINT IF EXISTS chk_attempts_status');
        $this->db->query(
            "ALTER TABLE reach_campaign_delivery_attempts ADD CONSTRAINT chk_attempts_status "
            . "CHECK (status IN ('queued','sending','accepted','sent','delivered','read','failed','bounced','complained','unsubscribed','suppressed'))"
        );
        // dispatch_id stays nullable: rows written without a dispatch cannot be re-parented.

        $this->db->query('DROP INDEX IF EXISTS idx_recipients_snapshot_eligibility');
        $this->db->query('ALTER TABLE reach_campaign_audience_recipients DROP COLUMN IF EXISTS subject_id');
        $this->db->query('ALTER TABLE reach_campaign_audience_recipients DROP COLUMN IF EXISTS subject_type');
    }
}
