<?php

namespace App\Libraries\Ai\Vetting;

/**
 * Vetting could not reach a verdict (no key, provider error, unusable
 * output). Callers leave the content pending and retry later — unvetted
 * content is never published.
 */
final class VettingUnavailableException extends \RuntimeException
{
}
