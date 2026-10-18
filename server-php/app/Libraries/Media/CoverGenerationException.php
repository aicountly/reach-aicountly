<?php

declare(strict_types=1);

namespace App\Libraries\Media;

/** A cover could not be generated; nothing was stored. */
final class CoverGenerationException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const PROVIDER_ERROR = 'provider_error';
    public const BAD_IMAGE      = 'bad_image';

    public function __construct(string $message, public readonly string $kind = self::PROVIDER_ERROR, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
