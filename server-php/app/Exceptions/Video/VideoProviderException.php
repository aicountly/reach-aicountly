<?php

declare(strict_types=1);

namespace App\Exceptions\Video;

class VideoProviderException extends \RuntimeException
{
    public function __construct(
        string                         $message = '',
        public readonly array          $context = [],
        public readonly ?int           $retryAfterSeconds = null,
        int                            $code = 0,
        ?\Throwable                    $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
