<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\NotificationService;
use App\Models\Content\ContentItemModel;
use App\Models\UserModel;

/**
 * Sends a daily digest notification to all active reviewers summarising
 * items pending review. Runs at 08:00 daily (registered in ReachSchedule).
 *
 * Payload: { "date": "YYYY-MM-DD" }
 */
class DailyApprovalDigestJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $date    = $payload['date'] ?? date('Y-m-d');
        $model   = new ContentItemModel();
        $notifSvc = new NotificationService();
        $users   = new UserModel();

        // Fetch all items pending review
        $pending = $model->where('workflow_status', 'review_pending')
                         ->where('deleted_at IS NULL')
                         ->findAll();

        if (empty($pending)) {
            return ['ok' => true, 'sent' => 0, 'date' => $date];
        }

        // Collect unique reviewer IDs via reach_content_assignments
        $db = \Config\Database::connect();
        $reviewerIds = $db->table('reach_content_assignments')
            ->whereIn('content_item_id', array_column($pending, 'id'))
            ->whereIn('role', ['reviewer', 'subject_matter_reviewer', 'compliance_reviewer'])
            ->where('is_active', true)
            ->select('DISTINCT user_id')
            ->get()
            ->getResultArray();

        $reviewerIds = array_column($reviewerIds, 'user_id');
        if (empty($reviewerIds)) {
            return ['ok' => true, 'sent' => 0, 'date' => $date];
        }

        $count = count($pending);
        $message = "You have {$count} content item(s) awaiting your review as of {$date}.";

        $notifSvc->dispatchToMany($reviewerIds, NotificationService::TYPE_DAILY_APPROVAL_DIGEST, $message, [
            'entity_type' => 'approval_digest',
            'entity_id'   => 0,
            'action_url'  => '/approvals',
            'data'        => ['date' => $date, 'count' => $count],
        ], null);

        return ['ok' => true, 'sent' => count($reviewerIds), 'pending_items' => $count, 'date' => $date];
    }
}
