<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\NotificationService;

/**
 * Escalates overdue content items: notifies admin owners.
 *
 * Payload: {}
 */
class ContentOverdueEscalationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $db       = \Config\Database::connect();
        $notifSvc = new NotificationService();

        $overdueItems = $db->table('reach_content_items ci')
            ->join('reach_content_briefs b', 'b.content_item_id = ci.id', 'left')
            ->whereIn('ci.workflow_status', ['draft', 'validation_pending', 'review_pending'])
            ->where('b.due_date IS NOT NULL')
            ->where('b.due_date <', date('Y-m-d'))
            ->where('ci.deleted_at IS NULL')
            ->select('ci.id, ci.title, ci.created_by, b.due_date')
            ->get()
            ->getResultArray();

        $escalated = 0;
        foreach ($overdueItems as $item) {
            // Notify the content creator/owner
            if ($item['created_by']) {
                $notifSvc->dispatch(
                    (int) $item['created_by'],
                    NotificationService::TYPE_REVIEW_OVERDUE,
                    "Content \"{$item['title']}\" is overdue (was due {$item['due_date']}).",
                    [
                        'entity_type' => 'content_item',
                        'entity_id'   => $item['id'],
                        'action_url'  => "/content/{$item['id']}",
                        'data'        => [
                            'content_title' => (string) $item['title'],
                            'due_date'      => (string) $item['due_date'],
                        ],
                    ],
                    null
                );
                $escalated++;
            }
        }

        return ['ok' => true, 'escalated' => $escalated];
    }
}
