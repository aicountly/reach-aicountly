<?php

declare(strict_types=1);

namespace App\Enums;

enum RefreshRecommendationStatus: string
{
    case Pending    = 'pending';
    case Recommended = 'recommended';
    case Triaged    = 'triaged';
    case Accepted   = 'accepted';
    case Rejected   = 'rejected';
    case Deferred   = 'deferred';
    case Superseded = 'superseded';
    case Expired    = 'expired';
}
