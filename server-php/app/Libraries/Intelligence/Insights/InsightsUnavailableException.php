<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

/**
 * AI Insights could not produce a report (not configured, provider error or
 * unusable output). The failed attempt is stored with this message.
 */
class InsightsUnavailableException extends \RuntimeException
{
}
