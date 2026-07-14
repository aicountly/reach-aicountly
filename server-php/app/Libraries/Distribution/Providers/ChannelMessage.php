<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

final class ChannelMessage
{
    public function __construct(
        public readonly string  $idempotencyKey,
        public readonly string  $recipientAddress,
        public readonly string  $content,
        public readonly ?string $templateId = null,
        public readonly array   $templateVars = [],
        public readonly array   $mediaRefs = [],
        public readonly array   $metadata = [],
    ) {}
}
