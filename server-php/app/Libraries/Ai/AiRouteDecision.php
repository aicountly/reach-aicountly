<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

final class AiRouteDecision
{
    public function __construct(
        public readonly AiProviderInterface $provider,
        public readonly string              $modelKey,
        public readonly ?int                $routeId,
        public readonly bool                $isMock,
    ) {
    }
}
