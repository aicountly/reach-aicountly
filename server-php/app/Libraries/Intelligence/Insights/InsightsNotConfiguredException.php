<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

/**
 * AI Insights cannot run because no Gemini key / model is set. A retry cannot
 * fix that, so the scheduled job is recorded as skipped, not failed.
 */
final class InsightsNotConfiguredException extends InsightsUnavailableException
{
}
