<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Jobs;

/**
 * Phase 7 distribution job type constants.
 * Used as `job_type` values in `reach_jobs` queue table.
 */
final class DistributionJobTypes
{
    public const CAMPAIGN_SCHEDULE        = 'distribution.campaign.schedule';
    public const CAMPAIGN_DISPATCH_SOCIAL = 'distribution.dispatch.social';
    public const CAMPAIGN_DISPATCH_EMAIL  = 'distribution.dispatch.email';
    public const CAMPAIGN_DISPATCH_WA     = 'distribution.dispatch.whatsapp';
    public const CAMPAIGN_DISPATCH_SMS    = 'distribution.dispatch.sms';
    public const CAMPAIGN_RECONCILE       = 'distribution.campaign.reconcile';

    public static function all(): array
    {
        return [
            self::CAMPAIGN_SCHEDULE,
            self::CAMPAIGN_DISPATCH_SOCIAL,
            self::CAMPAIGN_DISPATCH_EMAIL,
            self::CAMPAIGN_DISPATCH_WA,
            self::CAMPAIGN_DISPATCH_SMS,
            self::CAMPAIGN_RECONCILE,
        ];
    }
}
