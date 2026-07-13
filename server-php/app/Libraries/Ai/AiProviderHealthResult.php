<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

final class AiProviderHealthResult
{
    public function __construct(
        public readonly bool    $healthy,
        public readonly ?int    $responseTimeMs,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function status(): string
    {
        return $this->healthy ? 'healthy' : 'unhealthy';
    }
}
