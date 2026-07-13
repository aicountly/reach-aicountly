<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

final class BudgetCheckResult
{
    public function __construct(
        public readonly bool    $allowed,
        public readonly bool    $hardBlocked,
        public readonly ?string $scopeType    = null,
        public readonly ?string $scopeRef     = null,
        public readonly ?string $periodType   = null,
        public readonly float   $usedAmount   = 0.0,
        public readonly float   $hardLimit    = 0.0,
        public readonly bool    $nearWarning  = false,
    ) {
    }
}
