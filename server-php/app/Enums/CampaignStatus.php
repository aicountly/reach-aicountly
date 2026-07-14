<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft              = 'draft';
    case Preparing          = 'preparing';
    case ReadyForReview     = 'ready_for_review';
    case InReview           = 'in_review';
    case Approved           = 'approved';
    case Scheduled          = 'scheduled';
    case Dispatching        = 'dispatching';
    case PartiallyCompleted = 'partially_completed';
    case Completed          = 'completed';
    case ChangesRequested   = 'changes_requested';
    case Rejected           = 'rejected';
    case Paused             = 'paused';
    case Cancelled          = 'cancelled';
    case Failed             = 'failed';
    case DeadLettered       = 'dead_lettered';
    case Expired            = 'expired';
    case Withdrawn          = 'withdrawn';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    private function allowedTransitions(): array
    {
        return match($this) {
            self::Draft              => [self::Preparing, self::Cancelled],
            self::Preparing          => [self::ReadyForReview, self::Draft, self::Cancelled],
            self::ReadyForReview     => [self::InReview, self::Draft, self::Cancelled],
            self::InReview           => [self::Approved, self::ChangesRequested, self::Rejected],
            self::Approved           => [self::Scheduled, self::Dispatching, self::Cancelled],
            self::Scheduled          => [self::Dispatching, self::Cancelled, self::Expired],
            self::Dispatching        => [self::PartiallyCompleted, self::Completed, self::Paused, self::Cancelled, self::Failed],
            self::PartiallyCompleted => [self::Completed, self::Failed, self::DeadLettered],
            self::ChangesRequested   => [self::Draft],
            self::Paused             => [self::Dispatching, self::Cancelled],
            self::Failed             => [self::DeadLettered],
            default                  => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Rejected,
            self::Cancelled,
            self::DeadLettered,
            self::Expired,
            self::Withdrawn,
        ], true);
    }
}
