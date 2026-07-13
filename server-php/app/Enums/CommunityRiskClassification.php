<?php

namespace App\Enums;

enum CommunityRiskClassification: string
{
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';

    public function requiresProfessionalReview(): bool
    {
        return $this === self::High || $this === self::Critical;
    }

    public function requiresComplianceReview(): bool
    {
        return $this === self::Critical;
    }

    public function blocksPublicationUntilApproved(): bool
    {
        return true;
    }
}
