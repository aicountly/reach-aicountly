<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Paid-ads register (Social & Ads → Ads).
 *
 * Reach does not connect to the Google Ads, Meta or LinkedIn ads APIs. The
 * owner places each campaign by hand in that platform's ads manager and
 * records it here; results are typed in per day or imported from the ads
 * manager's standard CSV export (App\Libraries\Ads\AdsCsvImporter).
 *
 * reach_ad_campaigns — one row per campaign placed in an ads manager: platform,
 *   objective, planned daily budget, dates, landing URL, the UTM set its links
 *   carry, the creatives used (ids become utm_content) and its status.
 *   `external_ref` is the campaign's name in the ads manager, which is how CSV
 *   rows are matched to it.
 *
 * reach_ad_results — one row per campaign per day. Platform figures (spend,
 *   impressions, clicks, platform-reported conversions) and the sign-ups
 *   my.aicountly.com recorded for the campaign's UTM set are kept apart, each
 *   with where it came from. A NULL is "not entered", never zero.
 */
class CreateReachAds extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ad_campaigns (
                id               BIGSERIAL PRIMARY KEY,
                platform         VARCHAR(24)  NOT NULL CHECK (platform IN ('google_search','youtube','meta','linkedin')),
                name             VARCHAR(150) NOT NULL,
                external_ref     VARCHAR(200) NULL,
                objective        VARCHAR(24)  NOT NULL DEFAULT 'signups' CHECK (objective IN ('signups','leads','traffic','video_views','awareness')),
                daily_budget_inr NUMERIC(12,2) NULL CHECK (daily_budget_inr IS NULL OR daily_budget_inr >= 0),
                start_date       DATE         NOT NULL,
                end_date         DATE         NULL,
                landing_url      TEXT         NOT NULL,
                utm_source       VARCHAR(120) NOT NULL,
                utm_medium       VARCHAR(120) NOT NULL,
                utm_campaign     VARCHAR(240) NOT NULL,
                utm_content      VARCHAR(240) NULL,
                utm_term         VARCHAR(240) NULL,
                creatives        JSONB        NOT NULL DEFAULT '[]'::jsonb,
                status           VARCHAR(16)  NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','live','paused','ended','archived')),
                notes            TEXT         NULL,
                created_by       BIGINT       NULL,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT reach_ad_campaigns_dates CHECK (end_date IS NULL OR end_date >= start_date)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_ad_campaigns_utm ON reach_ad_campaigns (utm_source, utm_campaign)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_ad_campaigns_status ON reach_ad_campaigns (status)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ad_results (
                id               BIGSERIAL PRIMARY KEY,
                ad_campaign_id   BIGINT       NOT NULL REFERENCES reach_ad_campaigns(id) ON DELETE CASCADE,
                result_date      DATE         NOT NULL,
                spend_inr        NUMERIC(14,2) NULL CHECK (spend_inr IS NULL OR spend_inr >= 0),
                impressions      BIGINT       NULL CHECK (impressions IS NULL OR impressions >= 0),
                clicks           BIGINT       NULL CHECK (clicks IS NULL OR clicks >= 0),
                conversions      NUMERIC(12,2) NULL CHECK (conversions IS NULL OR conversions >= 0),
                metrics_source   VARCHAR(24)  NULL,
                signups          INTEGER      NULL CHECK (signups IS NULL OR signups >= 0),
                signups_source   VARCHAR(24)  NULL,
                created_by       BIGINT       NULL,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_reach_ad_results_day UNIQUE (ad_campaign_id, result_date)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_reach_ad_results_date ON reach_ad_results (result_date)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_ad_results CASCADE');
        $this->db->query('DROP TABLE IF EXISTS reach_ad_campaigns CASCADE');
    }
}
