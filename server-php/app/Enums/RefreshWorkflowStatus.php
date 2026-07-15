<?php

declare(strict_types=1);

namespace App\Enums;

enum RefreshWorkflowStatus: string
{
    case Accepted         = 'accepted';
    case BriefPrepared    = 'brief_prepared';
    case DraftGenerating  = 'draft_generating';
    case DraftReady       = 'draft_ready';
    case InReview         = 'in_review';
    case Approved         = 'approved';
    case PublishQueued    = 'publish_queued';
    case Published        = 'published';
    case Monitoring       = 'monitoring';
    case OutcomeRecorded  = 'outcome_recorded';
    case Rejected         = 'rejected';
    case Deferred         = 'deferred';
    case ChangesRequested = 'changes_requested';
    case Blocked          = 'blocked';
    case Cancelled        = 'cancelled';
    case Superseded       = 'superseded';
    case Failed           = 'failed';
    case Withdrawn        = 'withdrawn';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected, self::Cancelled, self::Superseded, self::Failed, self::Withdrawn, self::OutcomeRecorded,
        ], true);
    }
}
